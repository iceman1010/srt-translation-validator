<?php

namespace SrtValidator;

use Done\Subtitles\Subtitles;
use LanguageDetection\Language;

final class SrtTranslationValidator
{
    /** Captions per language-detection chunk. */
    private const CHUNK_SIZE = 500;
    /** Chunk step size: chunks overlap by 50% for better coverage. */
    private const CHUNK_STEP = 250;
    /** Captions skipped at the start and end of the file (credits, intros, logos). */
    private const GUARD_CAPTIONS = 10;
    /** Minimum chunk size in characters for reliable language detection. */
    private const MIN_CHUNK_CHARS = 5000;
    /** Minimum detection confidence to report a wrong-language chunk. */
    private const MIN_CONFIDENCE = 0.35;

    /** Maximum caption text length kept in missing-caption defects. */
    private const TEXT_PREVIEW_LENGTH = 100;

    /**
     * Longest run of source captions absorbed between two anchored
     * translation cues that is still classified as a merge. Longer runs are
     * treated as real content loss. Real-world re-segmenters merge 2-3
     * captions; a longer gap means the translator dropped content.
     */
    private const MAX_MERGE_SPAN = 3;

    private const DEFAULT_MAX_LOSS_RATIO = 0.01;
    private const DEFAULT_MAX_DRIFT_RATIO = 0.02;
    private const DEFAULT_MAX_PARTIAL_RATIO = 0.05;
    private const DEFAULT_MAX_MERGE_RATIO = 0.10;

    private Language $languageDetector;
    private SubtitleFormatValidator $formatValidator;
    private float $timestampTolerance = 0.5;

    private float $maxLossRatio = self::DEFAULT_MAX_LOSS_RATIO;
    private float $maxDriftRatio = self::DEFAULT_MAX_DRIFT_RATIO;
    private float $maxPartialRatio = self::DEFAULT_MAX_PARTIAL_RATIO;
    private float $maxMergeRatio = self::DEFAULT_MAX_MERGE_RATIO;
    private bool $strict = false;

    /**
     * The detector is injectable because the plain `new Language()`
     * constructor does not work inside a PHAR archive: its resource loader
     * uses glob(), which cannot expand patterns over the phar:// stream
     * wrapper and silently yields zero tokens. The CLI therefore builds a
     * PHAR-safe detector and passes it in.
     */
    public function __construct(?Language $languageDetector = null)
    {
        $this->languageDetector = $languageDetector ?? new Language();
        $this->formatValidator = new SubtitleFormatValidator();
    }

    public function setTimestampTolerance(float $seconds): void
    {
        $this->timestampTolerance = $seconds;
    }

    /** Fail on any error-severity defect instead of judging by ratios. */
    public function setStrict(bool $strict): void
    {
        $this->strict = $strict;
    }

    /** @param float|null $ratio null keeps the current value */
    public function setMaxLossRatio(?float $ratio): void
    {
        if ($ratio !== null) {
            $this->maxLossRatio = $ratio;
        }
    }

    public function setMaxDriftRatio(?float $ratio): void
    {
        if ($ratio !== null) {
            $this->maxDriftRatio = $ratio;
        }
    }

    public function setMaxPartialRatio(?float $ratio): void
    {
        if ($ratio !== null) {
            $this->maxPartialRatio = $ratio;
        }
    }

    public function setMaxMergeRatio(?float $ratio): void
    {
        if ($ratio !== null) {
            $this->maxMergeRatio = $ratio;
        }
    }

    /**
     * Compares a translation against its original and returns a result array:
     * ['valid' => bool, 'defects' => list<array>, 'error_count' => int,
     * 'warning_count' => int, 'quality' => array]. Each defect carries a
     * 'type', a 'severity' ('error' or 'warning') and type-specific fields
     * (see README for the catalog).
     *
     * 'valid' answers "is this translation usable?": it is true when no
     * quality ratio exceeds its threshold (or, in strict mode, when there
     * are no error-severity defects at all). Harmless re-segmentation
     * (merged/split captions) is reported as warnings and never fails.
     */
    public function validate(string $originalPath, string $translationPath, string $expectedLanguage): array
    {
        $originalFormat = $this->formatValidator->validateFile($originalPath);
        $translationFormat = $this->formatValidator->validateFile($translationPath);

        foreach ([$originalFormat, $translationFormat] as $format) {
            if ($format['format'] === null || !$format['valid']) {
                return $this->buildResult($this->formatDefects($format));
            }
        }

        if ($originalFormat['format'] !== $translationFormat['format']) {
            return $this->buildResult([[
                'type' => 'invalid_format',
                'severity' => 'error',
                'message' => "Format mismatch: original file is {$originalFormat['format']} but translation is {$translationFormat['format']}"
            ]]);
        }

        $original = $this->parseSubtitles($originalPath);
        $translation = $this->parseSubtitles($translationPath);
        if ($original === null || $translation === null) {
            return $this->buildResult([[
                'type' => 'invalid_format',
                'severity' => 'error',
                'message' => 'Failed to parse subtitle file after format validation passed'
            ]]);
        }

        $originalBlocks = $original->getInternalFormat();
        $translationBlocks = $translation->getInternalFormat();

        $aligner = new CaptionAligner();
        $events = $aligner->align($originalBlocks, $translationBlocks, $this->timestampTolerance);
        [$alignmentDefects, $stats] = $this->alignmentDefects($events, $originalBlocks, $translationBlocks);

        $partial = $this->detectPartialTranslation($translationBlocks, $expectedLanguage);

        $defects = array_merge($alignmentDefects, $partial['defects']);

        return $this->buildResult($defects, [
            'source_captions' => $stats['source_captions'],
            'aligned_pairs' => $stats['aligned_pairs'],
            'partial_chars_analyzed' => $partial['analyzed_chars'],
            'ratios' => [
                'content_loss' => $stats['loss_ratio'],
                'timestamp_drift' => $stats['drift_ratio'],
                'partial_translation' => $partial['analyzed_chars'] > 0
                    ? round($partial['wrong_chars'] / $partial['analyzed_chars'], 4)
                    : 0.0,
                'merged' => $stats['merge_ratio'],
            ],
            'thresholds' => [
                'content_loss' => $this->maxLossRatio,
                'timestamp_drift' => $this->maxDriftRatio,
                'partial_translation' => $this->maxPartialRatio,
                'merged' => $this->maxMergeRatio,
            ],
            'strict' => $this->strict,
        ]);
    }

    /**
     * Assembles the result array: severity counts, the verdict and, when the
     * comparison ran, the quality block with the failure reasons.
     *
     * @param list<array> $defects
     * @param array<string, mixed>|null $quality
     * @return array<string, mixed>
     */
    private function buildResult(array $defects, ?array $quality = null): array
    {
        $errorCount = 0;
        $warningCount = 0;
        foreach ($defects as $defect) {
            if (($defect['severity'] ?? 'error') === 'warning') {
                $warningCount++;
            } else {
                $errorCount++;
            }
        }

        $reasons = [];
        if ($quality !== null) {
            $reasons = $this->verdictReasons($quality['ratios'], $quality['thresholds'], $errorCount);
            $quality['reasons'] = $reasons;
        } elseif ($errorCount > 0) {
            $reasons = ['subtitle format is invalid'];
        }

        return [
            'valid' => $reasons === [],
            'defects' => $defects,
            'error_count' => $errorCount,
            'warning_count' => $warningCount,
            'quality' => $quality ?? ['reasons' => $errorCount > 0 ? ['subtitle format is invalid'] : []],
        ];
    }

    /** @return list<string> */
    private function verdictReasons(array $ratios, array $thresholds, int $errorCount): array
    {
        if ($this->strict) {
            return $errorCount > 0
                ? ["strict mode: {$errorCount} error-severity defect(s)"]
                : [];
        }

        $reasons = [];
        foreach ($ratios as $name => $value) {
            if ($value > $thresholds[$name]) {
                $reasons[] = sprintf(
                    '%s %.2f%% exceeds the threshold %.2f%%',
                    str_replace('_', ' ', $name),
                    $value * 100,
                    $thresholds[$name] * 100
                );
            }
        }
        return $reasons;
    }

    private function formatDefects(array $formatResult): array
    {
        if (empty($formatResult['errors'])) {
            return [['type' => 'invalid_format', 'severity' => 'error', 'message' => 'Subtitle file could not be read or parsed']];
        }

        $defects = [];
        foreach ($formatResult['errors'] as $error) {
            $defects[] = [
                'type' => 'invalid_format',
                'severity' => 'error',
                'subtype' => $error['subtype'],
                'line' => $error['line'],
                'message' => $error['message']
            ];
        }
        return $defects;
    }

    private function parseSubtitles(string $path): ?Subtitles
    {
        try {
            return Subtitles::loadFromFile($path);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Turns alignment events into defects and per-file statistics.
     *
     * Policy: gaps between two anchored cues of at most MAX_MERGE_SPAN
     * source captions are merges (warning); anything else a re-segmenter
     * would not produce is real content loss (error). Anchored pairs only
     * fail when their timing actually drifts beyond the tolerance.
     *
     * @param list<array<string, mixed>> $events
     * @param list<array{start: float, end: float, lines: list<string>}> $originalBlocks
     * @param list<array{start: float, end: float, lines: list<string>}> $translationBlocks
     * @return array{0: list<array>, 1: array{source_captions: int, aligned_pairs: int, loss_ratio: float, drift_ratio: float, merge_ratio: float}}
     */
    private function alignmentDefects(array $events, array $originalBlocks, array $translationBlocks): array
    {
        $defects = [];
        $pairs = 0;
        $driftedPairs = 0;
        $missingCaptions = 0;
        $mergedCaptions = 0;

        foreach ($events as $event) {
            switch ($event['kind']) {
                case 'match':
                case 'drift':
                    $pairs++;
                    $i = $event['source_index'];
                    $j = $event['translation_index'];

                    if ($event['start_diff'] <= $this->timestampTolerance
                        && $event['end_diff'] <= $this->timestampTolerance) {
                        break;
                    }

                    $driftedPairs++;
                    $defects[] = [
                        'type' => 'timestamp_mismatch',
                        'severity' => 'error',
                        'message' => 'Caption #' . ($i + 1) . ' has timestamp drift',
                        'caption_number' => $i + 1,
                        'original_start' => $originalBlocks[$i]['start'],
                        'translation_start' => $translationBlocks[$j]['start'],
                        'start_diff' => $event['start_diff'],
                        'original_end' => $originalBlocks[$i]['end'],
                        'translation_end' => $translationBlocks[$j]['end'],
                        'end_diff' => $event['end_diff']
                    ];
                    break;

                case 'gap':
                    $indices = $event['source_indices'];
                    $interior = $event['after_translation_index'] !== null
                        && $event['before_translation_index'] !== null;

                    if ($interior && count($indices) <= self::MAX_MERGE_SPAN) {
                        $mergedCaptions += count($indices);
                        $first = $indices[0] + 1;
                        $last = $indices[count($indices) - 1] + 1;
                        $defects[] = [
                            'type' => 'merged_captions',
                            'severity' => 'warning',
                            'message' => 'Original captions #' . ($first === $last ? $first : "{$first}-{$last}")
                                . ' are merged into translation caption #' . ($event['before_translation_index'] + 1),
                            'source_start_caption' => $first,
                            'source_end_caption' => $last,
                            'translation_caption' => $event['before_translation_index'] + 1,
                            'caption_count' => count($indices)
                        ];
                        break;
                    }

                    foreach ($indices as $i) {
                        $missingCaptions++;
                        $originalText = implode(' ', $originalBlocks[$i]['lines']);
                        $defects[] = [
                            'type' => 'missing_caption',
                            'severity' => 'error',
                            'message' => 'Caption #' . ($i + 1) . ' is missing in translation',
                            'caption_number' => $i + 1,
                            'original_text' => substr($originalText, 0, self::TEXT_PREVIEW_LENGTH)
                        ];
                    }
                    break;

                case 'split_part':
                    $defects[] = [
                        'type' => 'split_captions',
                        'severity' => 'warning',
                        'message' => 'Original caption #' . ($event['source_index'] + 1)
                            . ' is split across multiple translation captions',
                        'source_caption' => $event['source_index'] + 1,
                        'translation_caption' => $event['translation_index'] + 1
                    ];
                    break;

                case 'extra':
                    $defects[] = [
                        'type' => 'extra_caption',
                        'severity' => 'warning',
                        'message' => 'Translation caption #' . ($event['translation_index'] + 1)
                            . ' has no counterpart in the original',
                        'translation_caption' => $event['translation_index'] + 1
                    ];
                    break;
            }
        }

        $sourceCount = count($originalBlocks);

        return [$defects, [
            'source_captions' => $sourceCount,
            'aligned_pairs' => $pairs,
            'loss_ratio' => $sourceCount > 0 ? round($missingCaptions / $sourceCount, 4) : 0.0,
            'drift_ratio' => $pairs > 0 ? round($driftedPairs / $pairs, 4) : 0.0,
            'merge_ratio' => $sourceCount > 0 ? round($mergedCaptions / $sourceCount, 4) : 0.0,
        ]];
    }

    /**
     * Chunked language detection over the translation. Returns the defects
     * plus the character totals used for the partial-translation ratio.
     *
     * @param list<array{lines: list<string>}> $blocks
     * @return array{defects: list<array>, wrong_chars: int, analyzed_chars: int}
     */
    private function detectPartialTranslation(array $blocks, string $expectedLanguage): array
    {
        $expectedLanguage = strtolower($expectedLanguage);

        $defects = [];
        $wrongChars = 0;
        $analyzedChars = 0;
        $endIndex = count($blocks) - self::GUARD_CAPTIONS;

        for ($chunkStart = self::GUARD_CAPTIONS; $chunkStart < $endIndex; $chunkStart += self::CHUNK_STEP) {
            $chunkEnd = min($chunkStart + self::CHUNK_SIZE, $endIndex);
            $chunkText = $this->collectChunkText($blocks, $chunkStart, $chunkEnd);

            if (strlen($chunkText) < self::MIN_CHUNK_CHARS) {
                continue;
            }

            $analyzedChars += strlen($chunkText);

            $detections = $this->languageDetector->detect($chunkText)->close();
            if (!$detections) {
                continue;
            }

            $detectedLanguage = strtolower((string)array_key_first($detections));
            $confidence = reset($detections);

            if ($detectedLanguage !== $expectedLanguage && $confidence > self::MIN_CONFIDENCE) {
                $wrongChars += strlen($chunkText);
                $defects[] = [
                    'type' => 'partial_translation',
                    'severity' => 'error',
                    'message' => 'Large block (captions ' . ($chunkStart + 1) . "-{$chunkEnd}) detected as {$detectedLanguage} instead of {$expectedLanguage} - " . ($chunkEnd - $chunkStart) . ' captions, ' . strlen($chunkText) . ' chars',
                    'start_caption' => $chunkStart + 1,
                    'end_caption' => $chunkEnd,
                    'detected_language' => $detectedLanguage,
                    'confidence' => $confidence,
                    'chunk_chars' => strlen($chunkText),
                    'chunk_captions' => $chunkEnd - $chunkStart
                ];
            }
        }

        return ['defects' => $defects, 'wrong_chars' => $wrongChars, 'analyzed_chars' => $analyzedChars];
    }

    /**
     * Concatenates the caption text of a chunk, skipping lines that carry no
     * translatable language: very short lines, hearing-impaired annotations
     * in [brackets] and music symbols.
     */
    private function collectChunkText(array $blocks, int $start, int $end): string
    {
        $text = '';
        for ($i = $start; $i < $end; $i++) {
            $line = trim(implode(' ', $blocks[$i]['lines']));
            if (strlen($line) < 3) {
                continue;
            }
            if (preg_match('/^\[.*\]$/', $line)) {
                continue;
            }
            if (preg_match('/♪|♫/', $line)) {
                continue;
            }
            $text .= ' ' . $line;
        }
        return trim($text);
    }
}
