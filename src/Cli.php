<?php

namespace SrtValidator;

use Done\Subtitles\Subtitles;
use LanguageDetection\Language;

/**
 * Command-line front end for the subtitle translation validator.
 *
 * Expects exactly two positional arguments (the original and the translated
 * subtitle file), compares them, and prints a human-readable report. The
 * expected translation language is auto-detected unless given with --lang.
 */
final class Cli
{
    public const EXIT_OK = 0;
    public const EXIT_DEFECTS = 1;
    public const EXIT_ERROR = 2;

    private const AUTO_DETECT_MIN_CHARS = 500;
    private const REPORT_WIDTH = 66;

    public static function run(array $argv): int
    {
        $options = self::parseOptions(array_slice($argv, 1));

        if ($options === null) {
            return self::fail('unknown option or missing option value', in_array('--json', array_slice($argv, 1), true), true);
        }

        $json = $options['json'];

        if ($options['help']) {
            echo self::usage();
            return self::EXIT_OK;
        }

        if ($options['version']) {
            echo self::versionString() . "\n";
            return self::EXIT_OK;
        }

        if ($options['update'] !== null) {
            return self::update($options['update'] === '' ? null : $options['update']);
        }

        $files = $options['files'];
        if (count($files) !== 2) {
            return self::fail(sprintf('expected exactly two subtitle files, got %d', count($files)), $json, true);
        }

        [$original, $translation] = $files;

        foreach ([$original, $translation] as $path) {
            if (!is_file($path) || !is_readable($path)) {
                return self::fail('file does not exist or is not readable: ' . $path, $json);
            }
        }

        $detector = self::createLanguageDetector();

        $lang = $options['lang'] ?? self::autoDetectLanguage($translation, $detector);
        if ($lang === null) {
            return self::fail("could not auto-detect the language of '{$translation}'. Pass the expected language with --lang", $json);
        }

        $validator = new SrtTranslationValidator($detector);
        $validator->setTimestampTolerance($options['tolerance']);

        $result = $validator->validate($original, $translation, $lang);

        echo $json
            ? self::renderJson($original, $translation, $lang, $options['tolerance'], $result)
            : self::renderReport($original, $translation, $lang, $options['tolerance'], $result);

        return $result['valid'] ? self::EXIT_OK : self::EXIT_DEFECTS;
    }

    /**
     * Reports a usage or environment error as JSON (machine mode) or as a
     * human-readable stderr message, and returns the error exit code.
     */
    private static function fail(string $message, bool $json, bool $showUsage = false): int
    {
        if ($json) {
            echo self::encodeJson(['error' => $message]);
            return self::EXIT_ERROR;
        }

        self::stderr("Error: {$message}.\n");
        if ($showUsage) {
            self::stderr("\n" . self::usage());
        }
        return self::EXIT_ERROR;
    }

    public static function versionString(): string
    {
        return 'srt-translation-validator v' . self::version();
    }

    /**
     * Self-update for the PHAR build: fetches the latest (or a specific)
     * release from GitHub and replaces the running archive with it.
     */
    private static function update(?string $targetVersion): int
    {
        $pharPath = \Phar::running(false);
        if (empty($pharPath)) {
            self::stderr("Error: --update can only be used with the PHAR build.\n");
            return self::EXIT_DEFECTS;
        }

        $repo = self::releaseRepo();
        $ctx = stream_context_create(['http' => [
            'header' => 'User-Agent: srt-translation-validator',
            'timeout' => 30,
        ]]);

        echo "Current version: " . self::version() . "\n";

        if ($targetVersion !== null && $targetVersion !== '') {
            $targetVersion = ltrim($targetVersion, 'v');
            $apiUrl = "https://api.github.com/repos/{$repo}/releases/tags/v{$targetVersion}";
        } else {
            $apiUrl = "https://api.github.com/repos/{$repo}/releases/latest";
        }

        $json = @file_get_contents($apiUrl, false, $ctx);
        if ($json === false) {
            echo ($targetVersion !== null && $targetVersion !== '')
                ? "Error: Version v{$targetVersion} not found.\n"
                : "Error: Failed to fetch release info from GitHub.\n";
            return self::EXIT_DEFECTS;
        }

        $release = json_decode($json, true);

        if (isset($release['message']) && $release['message'] === 'Not Found') {
            echo "Error: Version v{$targetVersion} not found.\n";
            return self::EXIT_DEFECTS;
        }

        $latestTag = $release['tag_name'] ?? '';
        if ($latestTag === '') {
            echo "Error: Could not determine version.\n";
            return self::EXIT_DEFECTS;
        }

        echo "Target version: {$latestTag}\n";

        if ($targetVersion === null && 'v' . self::version() === $latestTag) {
            echo "Already up to date.\n";
            return self::EXIT_OK;
        }

        $downloadUrl = null;
        foreach ($release['assets'] ?? [] as $asset) {
            if (($asset['name'] ?? '') === 'srt-translation-validator.phar') {
                $downloadUrl = $asset['browser_download_url'] ?? null;
                break;
            }
        }
        if ($downloadUrl === null) {
            echo "Error: PHAR asset not found in release {$latestTag}.\n";
            return self::EXIT_DEFECTS;
        }

        echo "Downloading {$latestTag}...\n";
        $tmpFile = tempnam(sys_get_temp_dir(), 'srt-update-');
        if (!file_put_contents($tmpFile, file_get_contents($downloadUrl))) {
            echo "Error: Download failed.\n";
            @unlink($tmpFile);
            return self::EXIT_DEFECTS;
        }

        $header = file_get_contents($tmpFile, false, null, 0, 64);
        if (!str_contains($header, 'php')) {
            echo "Error: Downloaded file does not appear to be a valid PHAR.\n";
            @unlink($tmpFile);
            return self::EXIT_DEFECTS;
        }

        if (!is_writable($pharPath)) {
            echo "Error: Cannot write to {$pharPath}\n";
            echo "Run with sudo: sudo php " . basename($pharPath) . " --update\n";
            @unlink($tmpFile);
            return self::EXIT_DEFECTS;
        }

        if (!@rename($tmpFile, $pharPath)) {
            echo "Error: Failed to replace PHAR.\n";
            @unlink($tmpFile);
            return self::EXIT_DEFECTS;
        }

        chmod($pharPath, 0755);

        echo "Updated to {$latestTag}.\n";
        return self::EXIT_OK;
    }

    /**
     * The GitHub repository the tool is released from ("owner/repo"). Injected
     * into the PHAR stub at build time; falls back for manually built archives.
     */
    private static function releaseRepo(): string
    {
        if (defined('SRT_RELEASE_REPO') && constant('SRT_RELEASE_REPO') !== '') {
            return constant('SRT_RELEASE_REPO');
        }
        return 'iceman1010/srt-translation-validator';
    }

    public static function version(): string
    {
        $version = defined('SRT_VALIDATOR_VERSION') ? constant('SRT_VALIDATOR_VERSION') : null;
        if ($version === null) {
            $versionFile = __DIR__ . '/../VERSION';
            $version = is_file($versionFile) ? trim((string)file_get_contents($versionFile)) : 'dev';
        }
        return $version !== '' ? $version : 'dev';
    }

    public static function usage(): string
    {
        $executable = basename($_SERVER['argv'][0] ?? 'srt-validator');
        return <<<TXT
Subtitle Translation Validator
Compares an original subtitle file against a translation and reports defects.

USAGE:
  {$executable} <original-file> <translation-file> [options]

POSITIONAL ARGUMENTS:
  original-file       The reference subtitle file (SRT or WebVTT).
  translation-file    The subtitle file being validated (SRT or WebVTT).

OPTIONS:
  -l, --lang=CODE     Expected language of the translation (ISO 639-1, e.g. de).
                      Default: auto-detected from the translation text.
  -t, --tolerance=SEC Timestamp drift tolerance in seconds (default: 0.5).
  -j, --json          Output the report as JSON (machine-readable, for
                      scripts, agents and LLMs). Exit codes are unchanged.
  -h, --help          Show this help.
  -V, --version       Print the version and exit.
      --update[=ver]  Self-update the PHAR to the latest release (or to the
                      given version, e.g. --update=1.0.1). PHAR build only.

EXIT CODES:
  0  The translation is valid (no defects found).
  1  Defects were found (the translation is invalid), or a --update failed.
  2  Usage error, or the validator could not run.

EXAMPLES:
  {$executable} original.srt translation.de.srt -l de
  {$executable} original.en.srt translated.srt
  {$executable} -t 0.2 -l de Movie.en.srt Movie.pt.srt

TXT;
    }

    private static function parseOptions(array $args): ?array
    {
        $options = [
            'help' => false,
            'version' => false,
            'update' => null,
            'json' => false,
            'lang' => null,
            'tolerance' => 0.5,
            'files' => [],
        ];

        // Normalize "--opt=value" into "--opt" + "value" so every option is
        // handled as one flag-with-value pattern.
        $normalized = [];
        foreach ($args as $arg) {
            if (strpos($arg, '--') === 0 && strpos($arg, '=') !== false) {
                $normalized[] = substr($arg, 0, (int)strpos($arg, '='));
                $normalized[] = substr($arg, (int)strpos($arg, '=') + 1);
            } else {
                $normalized[] = $arg;
            }
        }

        $count = count($normalized);
        for ($i = 0; $i < $count; $i++) {
            $arg = $normalized[$i];

            if ($arg === '--help' || $arg === '-h') {
                $options['help'] = true;
                continue;
            }

            if ($arg === '--version' || $arg === '-V') {
                $options['version'] = true;
                continue;
            }

            if ($arg === '--json' || $arg === '-j') {
                $options['json'] = true;
                continue;
            }

            if ($arg === '--update') {
                // An optional version may follow; only consume it when it
                // looks like one, so filenames are never eaten.
                $next = $normalized[$i + 1] ?? null;
                if ($next !== null && preg_match('/^v?\d+(\.\d+)*$/', $next)) {
                    $options['update'] = ltrim($next, 'v');
                    $i++;
                } else {
                    $options['update'] = '';
                }
                continue;
            }

            if ($arg === '--lang' || $arg === '-l') {
                $value = $normalized[++$i] ?? '';
                if ($value === '') {
                    return null;
                }
                $options['lang'] = strtolower($value);
                continue;
            }

            if ($arg === '--tolerance' || $arg === '-t') {
                $value = $normalized[++$i] ?? '';
                if (!is_numeric($value) || (float)$value < 0) {
                    return null;
                }
                $options['tolerance'] = (float)$value;
                continue;
            }

            if ($arg !== '' && $arg[0] === '-') {
                return null; // unknown option
            }

            $options['files'][] = $arg;
        }

        return $options;
    }

    /**
     * Detects the dominant language of a subtitle file so the CLI can be used
     * without an explicit --lang.
     */
    private static function autoDetectLanguage(string $translationPath, ?Language $detector): ?string
    {
        if ($detector === null) {
            return null;
        }

        try {
            $subtitles = Subtitles::loadFromFile($translationPath);
        } catch (\Throwable $e) {
            return null;
        }

        $sample = '';
        foreach ($subtitles->getInternalFormat() as $block) {
            $text = trim(implode(' ', $block['lines']));
            if (strlen($text) < 3) {
                continue;
            }
            if (preg_match('/^\[.*\]$/', $text)) {
                continue;
            }
            if (preg_match('/♪|♫/', $text)) {
                continue;
            }
            $sample .= ' ' . $text;
            if (strlen($sample) >= self::AUTO_DETECT_MIN_CHARS * 40) {
                break;
            }
        }
        $sample = trim($sample);

        if (strlen($sample) < self::AUTO_DETECT_MIN_CHARS) {
            return null;
        }

        try {
            $result = $detector->detect($sample)->close();
        } catch (\Throwable $e) {
            return null;
        }

        $top = array_key_first($result);
        return $top !== null ? strtolower($top) : null;
    }

    /**
     * Builds a Language detector whose token resources are loaded by directory
     * iteration instead of glob().
     *
     * glob() cannot expand wildcard patterns against the phar:// stream wrapper,
     * so the plain `new Language()` constructor silently ends up with zero
     * tokens when this tool runs from inside a PHAR archive. Directory
     * iteration works over both regular paths and phar://.
     */
    private static function createLanguageDetector(): ?Language
    {
        $dir = __DIR__ . '/../vendor/patrickschur/language-detection/resources';
        if (!is_dir($dir)) {
            return null;
        }

        $tokens = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $resources = require $file->getPathname();
            if (!is_array($resources)) {
                continue;
            }
            foreach ($resources as $lang => $ngrams) {
                if (is_array($ngrams)) {
                    $tokens[$lang] = array_flip($ngrams);
                }
            }
        }

        if (empty($tokens)) {
            return null;
        }

        return new class($tokens) extends Language {
            /** @param array<string, array<int, int>> $tokens */
            public function __construct(array $tokens)
            {
                $this->tokens = $tokens;
            }
        };
    }

    /**
     * Machine-readable JSON report for scripts, agents and LLMs. Exit codes
     * carry the verdict (0 = valid, 1 = defects, 2 = error), so consumers can
     * rely on either the JSON fields or the process exit code.
     */
    private static function renderJson(string $original, string $translation, string $lang, float $tolerance, array $result): string
    {
        $defects = array_values($result['defects'] ?? []);

        $byType = [];
        foreach ($defects as $defect) {
            $type = $defect['type'] ?? 'unknown';
            $byType[$type] = ($byType[$type] ?? 0) + 1;
        }
        ksort($byType);

        $data = [
            'valid' => (bool)$result['valid'],
            'result' => $result['valid'] ? 'passed' : 'failed',
            'original' => $original,
            'translation' => $translation,
            'language' => $lang,
            'timestamp_tolerance' => $tolerance,
            'defect_count' => count($defects),
            'defects_by_type' => $byType,
            'defects' => $defects,
        ];

        return self::encodeJson($data);
    }

    private static function encodeJson(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }

    private static function renderReport(string $original, string $translation, string $lang, float $tolerance, array $result): string
    {
        $bar = str_repeat('=', self::REPORT_WIDTH);
        $dash = str_repeat('-', self::REPORT_WIDTH);

        $out = $bar . "\n";
        $out .= '  Subtitle Translation Validator' . "\n";
        $out .= $bar . "\n\n";

        $out .= self::line('Original', $original);
        $out .= self::line('Translation', $translation);
        $out .= self::line('Expected language', $lang);
        $out .= self::line('Timestamp tolerance', rtrim(rtrim(sprintf('%.3f', $tolerance), '0'), '.') . 's');
        $out .= "\n";

        $defects = $result['defects'];

        if (empty($defects)) {
            $out .= '  No defects found. The translation appears to be OK.' . "\n\n";
            $out .= $bar . "\n";
            $out .= '  RESULT: PASSED' . "\n";
            $out .= $bar . "\n";
            return $out;
        }

        $out .= sprintf('  Defects (%d)' . "\n", count($defects));
        $out .= $dash . "\n\n";

        $number = 0;
        foreach ($defects as $defect) {
            $number++;
            $out .= self::renderDefect($number, $defect);
            $out .= "\n";
        }

        $out .= $bar . "\n";
        $out .= sprintf('  RESULT: FAILED - %d defect(s) found' . "\n", count($defects));
        $out .= $bar . "\n";

        return $out;
    }

    private static function line(string $label, string $value): string
    {
        return sprintf('  %-19s %s' . "\n", $label . ':', $value);
    }

    private static function renderDefect(int $number, array $defect): string
    {
        $type = strtoupper(str_replace('_', ' ', $defect['type']));

        $meta = [];
        if (!empty($defect['subtype'])) {
            $meta[] = $defect['subtype'];
        }
        if (isset($defect['line']) && (int)$defect['line'] > 0) {
            $meta[] = 'line ' . $defect['line'];
        }
        if (isset($defect['caption_number'])) {
            $meta[] = 'caption #' . $defect['caption_number'];
        }

        $out = sprintf('  %d. %s', $number, $type);
        if ($meta) {
            $out .= ' [' . implode(', ', $meta) . ']';
        }
        $out .= "\n";

        if (!empty($defect['message'])) {
            $out .= self::wrap('  ' . $defect['message']) . "\n";
        }

        $details = [];

        if ($defect['type'] === 'timestamp_mismatch') {
            $details[] = 'original   : ' . self::formatTimecode($defect['original_start']) . ' --> ' . self::formatTimecode($defect['original_end']);
            $details[] = 'translation: ' . self::formatTimecode($defect['translation_start']) . ' --> ' . self::formatTimecode($defect['translation_end']);
            $details[] = sprintf(
                'drift      : start %+.3fs, end %+.3fs',
                $defect['start_diff'],
                $defect['end_diff']
            );
        }

        if ($defect['type'] === 'partial_translation') {
            $details[] = sprintf(
                'detected "%s" with confidence %.2f in captions %d-%d (%s chars)',
                $defect['detected_language'],
                $defect['confidence'],
                $defect['start_caption'],
                $defect['end_caption'],
                number_format($defect['chunk_chars'])
            );
        }

        if (!empty($defect['original_text'])) {
            $details[] = 'original text: ' . $defect['original_text'];
        }

        foreach ($details as $detail) {
            $out .= self::wrap('    ' . $detail) . "\n";
        }

        return $out;
    }

    private static function formatTimecode(float $seconds): string
    {
        $negative = $seconds < 0;
        $seconds = abs($seconds);

        $h = (int)floor($seconds / 3600);
        $m = (int)floor(($seconds % 3600) / 60);
        $s = (int)floor($seconds % 60);
        $ms = (int)round(($seconds - floor($seconds)) * 1000);

        if ($ms === 1000) {
            $ms = 0;
            $s++;
            if ($s === 60) {
                $s = 0;
                $m++;
                if ($m === 60) {
                    $m = 0;
                    $h++;
                }
            }
        }

        return sprintf('%s%02d:%02d:%02d,%03d', $negative ? '-' : '', $h, $m, $s, $ms);
    }

    private static function wrap(string $text): string
    {
        $text = trim($text);
        $indent = '';
        if (preg_match('/^(\s+)/', $text, $m)) {
            $indent = $m[1];
        }
        $wrapped = wordwrap($text, 100, "\n", false);
        return $indent . str_replace("\n", "\n" . $indent, $wrapped);
    }

    private static function stderr(string $message): void
    {
        fwrite(STDERR, $message);
    }
}