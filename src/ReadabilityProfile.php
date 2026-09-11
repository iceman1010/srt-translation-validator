<?php

declare(strict_types=1);

namespace SrtValidator;

use WhiteCube\Lingua\Service as LinguaService;

/**
 * Resolves per-language readability limits from
 * resources/readability-profiles.json.
 *
 * The file holds one "default" block (complete: cps, cpl, lines) plus a
 * "languages" map of exceptions. Exception entries are partial and merged
 * over the default, so {"cpl": 16} alone is valid. Values are backed by
 * published subtitling standards:
 *
 *   default  42 cpl / 20 cps  - Netflix Latin-script TTSG, matches BBC 37-42
 *   zh       16 cpl /  9 cps  - Netflix Chinese (Simplified/Traditional) TTSG
 *   ja       13 cpl /  4 cps  - Netflix Japanese TTSG (full-width counts)
 *
 * Language codes are canonicalized through whitecube/lingua (accepts
 * ISO 639-1/2/3, W3C/BCP-47 and PHP-style codes alike): the normalized
 * full tag is tried first ("zh-hant-tw"), then its ISO 639-1 primary
 * language ("zh"). No match anywhere - or no language given - falls back
 * to "default". A missing, unreadable or malformed profiles file throws -
 * never silently validate with wrong limits.
 */
final class ReadabilityProfile
{
    private const KEYS = ['cps', 'cpl', 'lines'];

    /** @var array<string, array<string, array{cps: float, cpl: int, lines: int}>> path => resolved table */
    private static array $cache = [];

    public static function defaultPath(): string
    {
        return dirname(__DIR__) . '/resources/readability-profiles.json';
    }

    /**
     * @param string|null $lang BCP-47-ish code; null/'' uses the default profile
     * @param string|null $file override profiles file (used by tests)
     * @return array{cps: float, cpl: int, lines: int}
     */
    public static function for(?string $lang, ?string $file = null): array
    {
        $profiles = self::load($file ?? self::defaultPath());

        foreach (self::candidates($lang) as $code) {
            if (isset($profiles[$code])) {
                return $profiles[$code];
            }
        }

        return $profiles['default'];
    }

    /**
     * Candidate codes, canonicalized by whitecube/lingua: the normalized
     * full tag first ("zh-hant-tw"), then the ISO 639-1 primary language
     * ("zh"). Unparseable input yields no candidates; unknown-but-parseable
     * codes simply miss in the profile table and use the default profile.
     *
     * @return list<string>
     */
    private static function candidates(?string $lang): array
    {
        if ($lang === null || trim($lang) === '') {
            return [];
        }

        try {
            $service = LinguaService::create(trim($lang));
            $full = strtolower((string) $service);
            $base = strtolower((string) $service->toISO_639_1());
        } catch (\Throwable $e) {
            return [];
        }

        $candidates = [];
        foreach ([$full, $base] as $code) {
            if ($code !== '' && !in_array($code, $candidates, true)) {
                $candidates[] = $code;
            }
        }

        return $candidates;
    }

    /**
     * Load, validate and merge the profiles file into a fully resolved
     * table (every entry complete).
     *
     * @return array<string, array{cps: float, cpl: int, lines: int}>
     */
    private static function load(string $file): array
    {
        $key = realpath($file) ?: $file;
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $raw = @file_get_contents($file);
        if ($raw === false) {
            throw new \RuntimeException('readability profiles file not found or not readable: ' . $file);
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException('readability profiles file is not valid JSON: ' . $file . ' (' . json_last_error_msg() . ')');
        }

        $table = [];

        /** @var array{cps: float, cpl: int, lines: int} $default */
        $default = self::entry($data['default'] ?? null, 'default', $file, complete: true);
        $table['default'] = $default;

        foreach ($data['languages'] ?? [] as $code => $entry) {
            if (!is_string($code) || $code === '') {
                throw new \RuntimeException('readability profiles file has an empty language key: ' . $file);
            }
            $table[strtolower($code)] = self::entry($entry, $code, $file, complete: false, default: $default);
        }

        return self::$cache[$key] = $table;
    }

    /**
     * Validate one entry and merge it over the default when partial.
     *
     * @return array{cps: float, cpl: int, lines: int}
     */
    private static function entry(mixed $entry, string $name, string $file, bool $complete, ?array $default = null): array
    {
        $where = 'readability profile "' . $name . '" in ' . $file;

        if (!is_array($entry)) {
            throw new \RuntimeException($where . ' must be an object');
        }

        $unknown = array_diff(array_keys($entry), self::KEYS);
        if ($unknown !== []) {
            throw new \RuntimeException($where . ' has unknown key(s): ' . implode(', ', $unknown));
        }
        if ($complete && ($missing = array_diff(self::KEYS, array_keys($entry))) !== []) {
            throw new \RuntimeException('readability profile "default" in ' . $file . ' is missing key(s): ' . implode(', ', $missing));
        }

        $merged = $complete ? [] : (array) $default;
        foreach (self::KEYS as $key) {
            if (!array_key_exists($key, $entry)) {
                continue;
            }
            $value = $entry[$key];
            if (!is_numeric($value) || $value <= 0) {
                throw new \RuntimeException($where . ' has invalid "' . $key . '" value: ' . var_export($value, true));
            }
            $merged[$key] = $key === 'cps' ? (float) $value : (int) $value;
        }

        return $merged;
    }
}
