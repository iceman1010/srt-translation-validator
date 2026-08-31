<?php

namespace SrtValidator;

/**
 * Detects translation letters written in scripts that do not belong to the
 * expected target language. This catches the "character hallucination"
 * failure mode of translation models: look-alike characters injected from a
 * foreign script (Cyrillic into a Latin-language translation, Devanagari
 * into Telugu, katakana into Chinese, ...).
 *
 * Characters outside every known script block (ASCII, punctuation, digits,
 * symbols, emoji, combining marks) are neutral and never flagged, so normal
 * subtitle punctuation and music symbols cannot raise false positives.
 */
final class ScriptChecker
{
    /**
     * Unicode block ranges per script. Only real letter scripts are listed;
     * everything else counts as neutral.
     *
     * @var array<string, list<array{0: int, 1: int}>>
     */
    private const SCRIPT_RANGES = [
        'latin' => [[0x00C0, 0x024F], [0x1E00, 0x1EFF], [0x2C60, 0x2C7F], [0xA720, 0xA7FF]],
        'greek' => [[0x0370, 0x03FF], [0x1F00, 0x1FFF]],
        'cyrillic' => [[0x0400, 0x052F], [0x2DE0, 0x2DFF], [0xA640, 0xA69F]],
        'hebrew' => [[0x0590, 0x05FF]],
        'arabic' => [[0x0600, 0x06FF], [0x0750, 0x077F], [0xFB50, 0xFDFF], [0xFE70, 0xFEFF]],
        'devanagari' => [[0x0900, 0x097F]],
        'bengali' => [[0x0980, 0x09FF]],
        'gurmukhi' => [[0x0A00, 0x0A7F]],
        'gujarati' => [[0x0A80, 0x0AFF]],
        'oriya' => [[0x0B00, 0x0B7F]],
        'tamil' => [[0x0B80, 0x0BFF]],
        'telugu' => [[0x0C00, 0x0C7F]],
        'kannada' => [[0x0C80, 0x0CFF]],
        'malayalam' => [[0x0D00, 0x0D7F]],
        'sinhala' => [[0x0D80, 0x0DFF]],
        'thai' => [[0x0E00, 0x0E7F]],
        'lao' => [[0x0E80, 0x0EFF]],
        'tibetan' => [[0x0F00, 0x0FFF]],
        'myanmar' => [[0x1000, 0x109F]],
        'khmer' => [[0x1780, 0x17FF]],
        'han' => [[0x2E80, 0x2EFF], [0x3000, 0x303F], [0x3400, 0x4DBF], [0x4E00, 0x9FFF], [0xF900, 0xFAFF], [0xFF00, 0xFFEF]],
        'hiragana' => [[0x3040, 0x309F]],
        'katakana' => [[0x30A0, 0x30FF]],
        'hangul' => [[0x1100, 0x11FF], [0x3130, 0x318F], [0xA960, 0xA97F], [0xAC00, 0xD7FF]],
        'armenian' => [[0x0530, 0x058F]],
        'georgian' => [[0x10A0, 0x10FF]],
    ];

    /**
     * Extra scripts allowed per language on top of Latin. Latin letters are
     * always allowed for every language: names, brands and numbers
     * legitimately stay in the source script.
     *
     * @var array<string, list<string>>
     */
    private const LANGUAGE_SCRIPTS = [
        'en' => [], 'de' => [], 'fr' => [], 'es' => [], 'it' => [], 'pt' => [], 'nl' => [],
        'sv' => [], 'no' => [], 'nb' => [], 'nn' => [], 'da' => [], 'fi' => [], 'is' => [],
        'hu' => [], 'pl' => [], 'cs' => [], 'sk' => [], 'sl' => [], 'hr' => [], 'bs' => [],
        'sr' => ['cyrillic'], 'ro' => [], 'lt' => [], 'lv' => [], 'et' => [], 'sq' => [],
        'tr' => [], 'az' => [], 'ca' => [], 'eu' => [], 'gl' => [], 'mt' => [], 'id' => [],
        'ms' => [], 'vi' => [], 'tl' => [], 'sw' => [], 'af' => [],
        'ru' => ['cyrillic'], 'uk' => ['cyrillic'], 'be' => ['cyrillic'], 'bg' => ['cyrillic'],
        'mk' => ['cyrillic'], 'kk' => ['cyrillic'], 'ky' => ['cyrillic'], 'uz' => ['cyrillic'],
        'tg' => ['cyrillic'], 'mn' => ['cyrillic'],
        'el' => ['greek'],
        'he' => ['hebrew'], 'yi' => ['hebrew'],
        'ar' => ['arabic'], 'fa' => ['arabic'], 'ur' => ['arabic'], 'ps' => ['arabic'], 'sd' => ['arabic'],
        'hi' => ['devanagari'], 'ne' => ['devanagari'], 'mr' => ['devanagari'], 'sa' => ['devanagari'],
        'bn' => ['bengali'], 'pa' => ['gurmukhi'], 'gu' => ['gujarati'], 'or' => ['oriya'],
        'ta' => ['tamil'], 'te' => ['telugu'], 'kn' => ['kannada'], 'ml' => ['malayalam'],
        'si' => ['sinhala'], 'th' => ['thai'], 'lo' => ['lao'], 'bo' => ['tibetan'],
        'my' => ['myanmar'], 'km' => ['khmer'],
        'zh' => ['han'], 'ja' => ['han', 'hiragana', 'katakana'], 'ko' => ['han', 'hangul'],
        'hy' => ['armenian'], 'ka' => ['georgian'],
    ];

    /** Max distinct example characters kept for the defect message. */
    private const MAX_EXAMPLES = 8;

    /**
     * Scans the translation captions for letters from unexpected scripts.
     * Returns null when the language is not in the map: the check is skipped
     * rather than guessed, because a wrong allow-list would produce false
     * positives.
     *
     * When the source captions are given, characters that already appear in
     * the source are exempt: the model merely preserved them (names, quotes,
     * on-screen text) and is not responsible for the source's own spelling.
     * Only characters the translation introduced itself are counted.
     *
     * @param list<array{lines: list<string>}> $blocks
     * @param list<array{lines: list<string>}>|null $sourceBlocks
     * @return array{letters: int, foreign_chars: int, scripts: array<string, int>, examples: list<string>}|null
     */
    public function check(array $blocks, string $language, ?array $sourceBlocks = null): ?array
    {
        $language = self::baseLanguage($language);
        if ($language === '' || !isset(self::LANGUAGE_SCRIPTS[$language])) {
            return null;
        }

        $allowed = array_merge(['latin'], self::LANGUAGE_SCRIPTS[$language]);
        $allowed = array_flip($allowed);

        $inherited = [];
        if ($sourceBlocks !== null) {
            foreach ($sourceBlocks as $block) {
                foreach ($block['lines'] as $line) {
                    foreach (mb_str_split($line) as $char) {
                        $inherited[$char] = true;
                    }
                }
            }
        }

        $letters = 0;
        $foreignChars = 0;
        $scripts = [];
        $examples = [];

        foreach ($blocks as $block) {
            foreach ($block['lines'] as $line) {
                foreach (mb_str_split($line) as $char) {
                    $script = $this->scriptOf($char);
                    if ($script === null) {
                        continue; // neutral character
                    }
                    $letters++;
                    if (isset($allowed[$script])) {
                        continue;
                    }
                    if (isset($inherited[$char])) {
                        continue; // spelled that way in the source already
                    }
                    $foreignChars++;
                    $scripts[$script] = ($scripts[$script] ?? 0) + 1;
                    if (count($examples) < self::MAX_EXAMPLES && !in_array($char, $examples, true)) {
                        $examples[] = $char;
                    }
                }
            }
        }

        return ['letters' => $letters, 'foreign_chars' => $foreignChars, 'scripts' => $scripts, 'examples' => $examples];
    }

    /**
     * Reduces a language tag to its base language: lowercased, with any
     * regional suffix stripped ("es-mx" / "pt_BR" -> "es" / "pt"), so
     * comparisons never fail on the region part.
     */
    public static function baseLanguage(string $language): string
    {
        $language = strtolower(trim($language));
        $dash = strcspn($language, '-_');
        if ($dash > 0 && $dash < strlen($language)) {
            $language = substr($language, 0, $dash);
        }
        return $language;
    }

    /**
     * The scripts distinctive to a language, i.e. its allowed scripts minus
     * Latin (which every language may use for names and brands). Empty list
     * for Latin-written languages, null when the language is unknown: for
     * those, script-based reasoning must be skipped rather than guessed.
     *
     * @return list<string>|null
     */
    public static function characteristicScripts(string $language): ?array
    {
        $language = self::baseLanguage($language);
        if ($language === '' || !isset(self::LANGUAGE_SCRIPTS[$language])) {
            return null;
        }
        return self::LANGUAGE_SCRIPTS[$language];
    }

    /**
     * Raw letter counts per script over the given captions (no exemptions),
     * used to reason about what script a file is actually written in.
     *
     * @param list<array{lines: list<string>}> $blocks
     * @return array<string, int>
     */
    public function scriptProfile(array $blocks): array
    {
        $profile = [];
        foreach ($blocks as $block) {
            foreach ($block['lines'] as $line) {
                foreach (mb_str_split($line) as $char) {
                    $script = $this->scriptOf($char);
                    if ($script !== null) {
                        $profile[$script] = ($profile[$script] ?? 0) + 1;
                    }
                }
            }
        }
        return $profile;
    }

    /** @return string|null the script name of a character, null when neutral */
    private function scriptOf(string $char): ?string
    {
        $code = mb_ord($char, 'UTF-8');
        if ($code === false) {
            return null;
        }
        foreach (self::SCRIPT_RANGES as $script => $ranges) {
            foreach ($ranges as [$from, $to]) {
                if ($code >= $from && $code <= $to) {
                    return $script;
                }
            }
        }
        return null;
    }
}
