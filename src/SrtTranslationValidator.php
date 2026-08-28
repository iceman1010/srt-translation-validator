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

    private Language $languageDetector;
    private SubtitleFormatValidator $formatValidator;
    private float $timestampTolerance = 0.5;

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

    /**
     * Compares a translation against its original and returns a result array:
     * ['valid' => bool, 'defects' => list<array>]. Each defect carries a
     * 'type' plus type-specific fields (see README for the catalog).
     */
    public function validate(string $originalPath, string $translationPath, string $expectedLanguage): array
    {
        $originalFormat = $this->formatValidator->validateFile($originalPath);
        $translationFormat = $this->formatValidator->validateFile($translationPath);

        foreach ([$originalFormat, $translationFormat] as $format) {
            if ($format['format'] === null || !$format['valid']) {
                return ['valid' => false, 'defects' => $this->formatDefects($format)];
            }
        }

        if ($originalFormat['format'] !== $translationFormat['format']) {
            return [
                'valid' => false,
                'defects' => [[
                    'type' => 'invalid_format',
                    'message' => "Format mismatch: original file is {$originalFormat['format']} but translation is {$translationFormat['format']}"
                ]]
            ];
        }

        $original = $this->parseSubtitles($originalPath);
        $translation = $this->parseSubtitles($translationPath);
        if ($original === null || $translation === null) {
            return [
                'valid' => false,
                'defects' => [['type' => 'invalid_format', 'message' => 'Failed to parse subtitle file after format validation passed']]
            ];
        }

        $defects = array_merge(
            $this->detectMissingParts($original, $translation),
            $this->detectPartialTranslation($translation, $expectedLanguage),
            $this->detectTimestampMismatch($original, $translation)
        );

        return ['valid' => $defects === [], 'defects' => $defects];
    }

    private function formatDefects(array $formatResult): array
    {
        if (empty($formatResult['errors'])) {
            return [['type' => 'invalid_format', 'message' => 'Subtitle file could not be read or parsed']];
        }

        $defects = [];
        foreach ($formatResult['errors'] as $error) {
            $defects[] = [
                'type' => 'invalid_format',
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

    private function detectMissingParts(Subtitles $original, Subtitles $translation): array
    {
        $originalBlocks = $original->getInternalFormat();
        $translationBlocks = $translation->getInternalFormat();

        $originalCount = count($originalBlocks);
        $translationCount = count($translationBlocks);

        if ($translationCount === $originalCount) {
            return [];
        }

        if ($translationCount > $originalCount) {
            return [[
                'type' => 'extra_parts',
                'message' => "Translation has {$translationCount} captions, original has {$originalCount}. Extra " . ($translationCount - $originalCount) . ' captions.'
            ]];
        }

        $defects = [[
            'type' => 'missing_parts',
            'message' => "Translation has {$translationCount} captions, original has {$originalCount}. Missing " . ($originalCount - $translationCount) . ' captions.'
        ]];

        for ($i = $translationCount; $i < $originalCount; $i++) {
            $originalText = implode(' ', $originalBlocks[$i]['lines']);
            $defects[] = [
                'type' => 'missing_caption',
                'message' => 'Caption #' . ($i + 1) . ' is missing in translation',
                'caption_number' => $i + 1,
                'original_text' => substr($originalText, 0, self::TEXT_PREVIEW_LENGTH)
            ];
        }

        return $defects;
    }

    private function detectPartialTranslation(Subtitles $translation, string $expectedLanguage): array
    {
        $blocks = $translation->getInternalFormat();
        $expectedLanguage = strtolower($expectedLanguage);

        $defects = [];
        $endIndex = count($blocks) - self::GUARD_CAPTIONS;

        for ($chunkStart = self::GUARD_CAPTIONS; $chunkStart < $endIndex; $chunkStart += self::CHUNK_STEP) {
            $chunkEnd = min($chunkStart + self::CHUNK_SIZE, $endIndex);
            $chunkText = $this->collectChunkText($blocks, $chunkStart, $chunkEnd);

            if (strlen($chunkText) < self::MIN_CHUNK_CHARS) {
                continue;
            }

            $detections = $this->languageDetector->detect($chunkText)->close();
            if (!$detections) {
                continue;
            }

            $detectedLanguage = strtolower((string)array_key_first($detections));
            $confidence = reset($detections);

            if ($detectedLanguage !== $expectedLanguage && $confidence > self::MIN_CONFIDENCE) {
                $defects[] = [
                    'type' => 'partial_translation',
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

        return $defects;
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

    private function detectTimestampMismatch(Subtitles $original, Subtitles $translation): array
    {
        $originalBlocks = $original->getInternalFormat();
        $translationBlocks = $translation->getInternalFormat();

        $defects = [];
        $comparedCount = min(count($originalBlocks), count($translationBlocks));

        for ($i = 0; $i < $comparedCount; $i++) {
            $startDiff = abs($originalBlocks[$i]['start'] - $translationBlocks[$i]['start']);
            $endDiff = abs($originalBlocks[$i]['end'] - $translationBlocks[$i]['end']);

            if ($startDiff <= $this->timestampTolerance && $endDiff <= $this->timestampTolerance) {
                continue;
            }

            $defects[] = [
                'type' => 'timestamp_mismatch',
                'message' => 'Caption #' . ($i + 1) . ' has timestamp drift',
                'caption_number' => $i + 1,
                'original_start' => $originalBlocks[$i]['start'],
                'translation_start' => $translationBlocks[$i]['start'],
                'start_diff' => $startDiff,
                'original_end' => $originalBlocks[$i]['end'],
                'translation_end' => $translationBlocks[$i]['end'],
                'end_diff' => $endDiff
            ];
        }

        return $defects;
    }
}
