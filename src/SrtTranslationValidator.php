<?php

namespace SrtValidator;

use Done\Subtitles\Subtitles;
use LanguageDetection\Language;

class SrtTranslationValidator
{
    private $languageDetector;
    private $formatValidator;
    private $timestampTolerance = 0.5; // 500ms tolerance for timestamp comparison

    public function __construct()
    {
        $this->languageDetector = new Language();
        $this->formatValidator = new SubtitleFormatValidator();
    }

    public function validate(string $originalPath, string $translationPath, string $expectedLanguage): array
    {
        $results = [
            'valid' => true,
            'defects' => []
        ];

        $originalFormat = $this->formatValidator->validateFile($originalPath);
        if ($originalFormat['format'] === null) {
            return [
                'valid' => false,
                'defects' => $this->formatDefects($originalFormat)
            ];
        }
        if (!$originalFormat['valid']) {
            return [
                'valid' => false,
                'defects' => $this->formatDefects($originalFormat)
            ];
        }

        $translationFormat = $this->formatValidator->validateFile($translationPath);
        if ($translationFormat['format'] === null) {
            return [
                'valid' => false,
                'defects' => $this->formatDefects($translationFormat)
            ];
        }
        if (!$translationFormat['valid']) {
            return [
                'valid' => false,
                'defects' => $this->formatDefects($translationFormat)
            ];
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

        $results['defects'] = array_merge(
            $results['defects'],
            $this->detectMissingParts($original, $translation),
            $this->detectPartialTranslation($translation, $expectedLanguage),
            $this->detectTimestampMismatch($original, $translation)
        );

        $results['valid'] = empty($results['defects']);

        return $results;
    }

    private function formatDefects(array $formatResult): array
    {
        $defects = [];
        if (empty($formatResult['errors'])) {
            $defects[] = ['type' => 'invalid_format', 'message' => 'Subtitle file could not be read or parsed'];
            return $defects;
        }
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
        } catch (\Exception $e) {
            return null;
        }
    }

    private function detectMissingParts(Subtitles $original, Subtitles $translation): array
    {
        $defects = [];
        $originalBlocks = $original->getInternalFormat();
        $translationBlocks = $translation->getInternalFormat();

        $originalCount = count($originalBlocks);
        $translationCount = count($translationBlocks);

        if ($translationCount < $originalCount) {
            $defects[] = [
                'type' => 'missing_parts',
                'message' => "Translation has {$translationCount} captions, original has {$originalCount}. Missing " . ($originalCount - $translationCount) . " captions."
            ];

            for ($i = 1; $i <= $originalCount; $i++) {
                $originalIndex = $i - 1;
                $translationIndex = $i - 1;

                if (!isset($translationBlocks[$translationIndex])) {
                    $originalText = implode(' ', $originalBlocks[$originalIndex]['lines']);
                    $defects[] = [
                        'type' => 'missing_caption',
                        'message' => "Caption #{$i} is missing in translation",
                        'caption_number' => $i,
                        'original_text' => substr($originalText, 0, 100)
                    ];
                }
            }
        } elseif ($translationCount > $originalCount) {
            $defects[] = [
                'type' => 'extra_parts',
                'message' => "Translation has {$translationCount} captions, original has {$originalCount}. Extra " . ($translationCount - $originalCount) . " captions."
            ];
        }

        return $defects;
    }

    private function detectPartialTranslation(Subtitles $translation, string $expectedLanguage): array
    {
        $defects = [];
        $blocks = $translation->getInternalFormat();

        $totalBlocks = count($blocks);
        $chunkSize = 500; // Test 500 captions at a time
        $stepSize = 250;  // Overlap by 50% for better coverage

        // Skip first few and last few captions (credits, etc.)
        $startIndex = 10;
        $endIndex = $totalBlocks - 10;

        for ($chunkStart = $startIndex; $chunkStart < $endIndex; $chunkStart += $stepSize) {
            $chunkEnd = min($chunkStart + $chunkSize, $endIndex);
            
            // Combine text from this chunk
            $combinedText = '';
            for ($i = $chunkStart; $i < $chunkEnd; $i++) {
                $text = implode(' ', $blocks[$i]['lines']);
                $textLength = strlen(trim($text));
                
                if ($textLength < 3) continue;
                if (preg_match('/^\[.*\]$/', trim($text))) continue;
                if (preg_match('/♪|♫/', $text)) continue;
                
                $combinedText .= ' ' . $text;
            }

            // Need enough text for reliable detection
            if (strlen(trim($combinedText)) < 5000) {
                continue;
            }

            $detections = $this->languageDetector->detect($combinedText);
            $detectionsArray = $detections->close();

            if (empty($detectionsArray)) {
                continue;
            }

            $detectedLang = array_key_first($detectionsArray);
            $confidence = $detectionsArray[$detectedLang];

            // If this large chunk is detected as wrong language with reasonable confidence
            if ($detectedLang !== strtolower($expectedLanguage) && $confidence > 0.35) {
                $defects[] = [
                    'type' => 'partial_translation',
                    'message' => "Large block (captions " . ($chunkStart + 1) . "-" . $chunkEnd . ") detected as {$detectedLang} instead of {$expectedLanguage} - " . ($chunkEnd - $chunkStart) . " captions, " . strlen($combinedText) . " chars",
                    'start_caption' => $chunkStart + 1,
                    'end_caption' => $chunkEnd,
                    'detected_language' => $detectedLang,
                    'confidence' => $confidence,
                    'chunk_chars' => strlen($combinedText),
                    'chunk_captions' => $chunkEnd - $chunkStart
                ];
            }
        }

        return $defects;
    }

    private function detectTimestampMismatch(Subtitles $original, Subtitles $translation): array
    {
        $defects = [];
        $originalBlocks = $original->getInternalFormat();
        $translationBlocks = $translation->getInternalFormat();

        $minCount = min(count($originalBlocks), count($translationBlocks));

        for ($i = 0; $i < $minCount; $i++) {
            $originalStart = $originalBlocks[$i]['start'];
            $originalEnd = $originalBlocks[$i]['end'];
            $translationStart = $translationBlocks[$i]['start'];
            $translationEnd = $translationBlocks[$i]['end'];

            $startDiff = abs($originalStart - $translationStart);
            $endDiff = abs($originalEnd - $translationEnd);

            if ($startDiff > $this->timestampTolerance || $endDiff > $this->timestampTolerance) {
                $defects[] = [
                    'type' => 'timestamp_mismatch',
                    'message' => "Caption #" . ($i + 1) . " has timestamp drift",
                    'caption_number' => $i + 1,
                    'original_start' => $originalStart,
                    'translation_start' => $translationStart,
                    'start_diff' => $startDiff,
                    'original_end' => $originalEnd,
                    'translation_end' => $translationEnd,
                    'end_diff' => $endDiff
                ];
            }
        }

        return $defects;
    }

    public function setTimestampTolerance(float $seconds): void
    {
        $this->timestampTolerance = $seconds;
    }
}