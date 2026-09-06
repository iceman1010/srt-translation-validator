<?php

namespace SrtValidator;

use Done\Subtitles\Subtitles;

/**
 * Extended per-caption readability analysis.
 *
 * Unlike the aggregate reading-speed/line-length stats in the quality block,
 * this walks every caption and lists the ones that exceed the readability
 * limits, each with the exact value against the limit and the cue text.
 * Purely advisory: it never produces defects or affects the translation
 * verdict; the CLI only enters this mode when explicitly asked with
 * --readability.
 *
 * A subtitle file can enter three ways:
 *   new ReadabilityChecker('Movie.srt')   parsed once at construction,
 *                                         analyze() then needs no arguments
 *   ->analyzeFile('Movie.srt')            one-shot; ignores stored blocks
 *   ->analyzeContent($srtString)          one-shot for content in memory
 * ->analyze($blocks) still accepts pre-parsed caption blocks when you
 * already have them. Unreadable input throws InvalidArgumentException,
 * unparseable input RuntimeException; there are no partial results.
 */
final class ReadabilityChecker
{
    /** Comfortable reading speed cap per caption (chars per second). */
    public const DEFAULT_MAX_CPS = 20.0;
    /** Common guideline: 42 characters per line. */
    public const DEFAULT_MAX_CPL = 42;
    /** Common guideline: two lines per caption. */
    public const DEFAULT_MAX_LINES = 2;

    /** Minimum cue duration (seconds) a caption needs to count for CPS. */
    private const MIN_CPS_DURATION = 0.2;

    /** @var list<array{start: float, end: float, lines: list<string>}>|null */
    private $blocks = null;

    /**
     * Optionally bind the checker to a subtitle file (.srt or .vtt); the
     * file is loaded and parsed immediately.
     */
    public function __construct(?string $file = null)
    {
        if ($file !== null) {
            $this->blocks = self::loadBlocks($file);
        }
    }

    /** Load, parse and audit a subtitle file (.srt or .vtt) in one call. */
    public function analyzeFile(string $file, ?float $maxCps = null, ?int $maxCpl = null, ?int $maxLines = null): array
    {
        return $this->analyze(self::loadBlocks($file), $maxCps, $maxCpl, $maxLines);
    }

    /** Parse and audit subtitle content (.srt or .vtt) held in memory. */
    public function analyzeContent(string $content, ?float $maxCps = null, ?int $maxCpl = null, ?int $maxLines = null): array
    {
        try {
            $blocks = Subtitles::loadFromString($content)->getInternalFormat();
        } catch (\Throwable $e) {
            throw new \RuntimeException('could not parse the subtitle content: ' . $e->getMessage(), 0, $e);
        }

        return $this->analyze($blocks, $maxCps, $maxCpl, $maxLines);
    }

    /**
     * @param list<array{start: float, end: float, lines: list<string>}>|null $blocks
     *        pre-parsed caption blocks; null uses the file bound at construction
     * @return array{
     *   captions: int,
     *   analyzed: int,
     *   avg_cps: float,
     *   max_cps: float,
     *   max_cps_caption: int|null,
     *   max_cpl: int,
     *   max_cpl_caption: int|null,
     *   thresholds: array{max_cps: float, max_cpl: int, max_lines: int},
     *   problems_by_type: array<string, int>,
     *   problems: list<array{
     *     caption: int,
     *     start_seconds: float,
     *     end_seconds: float,
     *     duration_seconds: float,
     *     chars: int,
     *     cps: float|null,
     *     lines: list<string>,
     *     text: string,
     *     severity: string,
     *     issues: list<array{type: string, value: int|float, limit: int|float, severity: string}>
     *   }>
     * }
     */
    public function analyze(
        ?array $blocks = null,
        ?float $maxCps = null,
        ?int $maxCpl = null,
        ?int $maxLines = null
    ): array {
        if ($blocks === null) {
            $blocks = $this->blocks;
        }
        if ($blocks === null) {
            throw new \InvalidArgumentException(
                'no subtitle file was bound at construction and no caption blocks were passed to analyze()'
            );
        }

        $maxCps = $maxCps ?? self::DEFAULT_MAX_CPS;
        $maxCpl = $maxCpl ?? self::DEFAULT_MAX_CPL;
        $maxLines = $maxLines ?? self::DEFAULT_MAX_LINES;

        $totalChars = 0;
        $totalDuration = 0.0;
        $maxObservedCps = 0.0;
        $maxObservedCpsCaption = null;
        $maxObservedCpl = 0;
        $maxObservedCplCaption = null;
        $analyzed = 0;

        $problems = [];

        foreach ($blocks as $index => $block) {
            $caption = $index + 1;
            $lines = array_map('strval', $block['lines']);
            $text = implode(' ', $lines);
            $chars = mb_strlen($text);
            $start = (float)($block['start'] ?? 0);
            $end = (float)($block['end'] ?? 0);
            $duration = $end - $start;

            $maxLine = 0;
            foreach ($lines as $line) {
                $maxLine = max($maxLine, mb_strlen($line));
            }
            if ($maxLine > $maxObservedCpl) {
                $maxObservedCpl = $maxLine;
                $maxObservedCplCaption = $caption;
            }

            $cps = null;
            if ($chars > 0 && $duration >= self::MIN_CPS_DURATION) {
                $cps = $chars / $duration;
                $totalChars += $chars;
                $totalDuration += $duration;
                $analyzed++;
                if ($cps > $maxObservedCps) {
                    $maxObservedCps = $cps;
                    $maxObservedCpsCaption = $caption;
                }
            }

            $issues = [];
            if ($cps !== null && $cps > $maxCps) {
                $issues[] = [
                    'type' => 'reading_speed',
                    'value' => round($cps, 1),
                    'limit' => $maxCps,
                    'severity' => self::severity(round($cps, 1), $maxCps),
                ];
            }
            if ($maxLine > $maxCpl) {
                $issues[] = [
                    'type' => 'line_length',
                    'value' => $maxLine,
                    'limit' => $maxCpl,
                    'severity' => self::severity($maxLine, $maxCpl),
                ];
            }
            if (count($lines) > $maxLines) {
                $issues[] = [
                    'type' => 'line_count',
                    'value' => count($lines),
                    'limit' => $maxLines,
                    'severity' => self::severity(count($lines), $maxLines),
                ];
            }

            if ($issues !== []) {
                // A caption is "critical" when any of its issues is critical.
                $severity = 'minor';
                foreach ($issues as $issue) {
                    if ($issue['severity'] === 'critical') {
                        $severity = 'critical';
                        break;
                    }
                }
                $problems[] = [
                    'caption' => $caption,
                    'start_seconds' => round($start, 3),
                    'end_seconds' => round($end, 3),
                    'duration_seconds' => round($duration, 3),
                    'chars' => $chars,
                    'cps' => $cps !== null ? round($cps, 1) : null,
                    'lines' => $lines,
                    'text' => $text,
                    'severity' => $severity,
                    'issues' => $issues,
                ];
            }
        }

        $byType = [];
        foreach ($problems as $problem) {
            foreach ($problem['issues'] as $issue) {
                $byType[$issue['type']] = ($byType[$issue['type']] ?? 0) + 1;
            }
        }

        return [
            'captions' => count($blocks),
            'analyzed' => $analyzed,
            'avg_cps' => $totalDuration > 0 ? round($totalChars / $totalDuration, 1) : 0.0,
            'max_cps' => round($maxObservedCps, 1),
            'max_cps_caption' => $maxObservedCpsCaption,
            'max_cpl' => $maxObservedCpl,
            'max_cpl_caption' => $maxObservedCplCaption,
            'thresholds' => ['max_cps' => $maxCps, 'max_cpl' => $maxCpl, 'max_lines' => $maxLines],
            'problems_by_type' => $byType,
            'problems' => $problems,
        ];
    }

    /**
     * A caption is flagged "critical" when its value exceeds twice the limit,
     * otherwise "minor". LLM consumers can sort/prioritize without
     * re-deriving the math.
     */
    private static function severity(int|float $value, int|float $limit): string
    {
        return $value > 2 * $limit ? 'critical' : 'minor';
    }

    /** @return list<array{start: float, end: float, lines: list<string>}> */
    private static function loadBlocks(string $file): array
    {
        if (!is_file($file) || !is_readable($file)) {
            throw new \InvalidArgumentException('subtitle file does not exist or is not readable: ' . $file);
        }

        try {
            return Subtitles::loadFromFile($file)->getInternalFormat();
        } catch (\Throwable $e) {
            throw new \RuntimeException('could not parse the subtitle file: ' . $file . ' (' . $e->getMessage() . ')', 0, $e);
        }
    }
}
