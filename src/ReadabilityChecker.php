<?php

namespace SrtValidator;

/**
 * Extended per-caption readability analysis.
 *
 * Unlike the aggregate reading-speed/line-length stats in the quality block,
 * this walks every caption and lists the ones that exceed the readability
 * limits, each with the exact value against the limit and the cue text.
 * Purely advisory: it never produces defects or affects the translation
 * verdict; the CLI only enters this mode when explicitly asked with
 * --readability.
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

    /**
     * A caption is flagged "critical" when its value exceeds twice the limit,
     * otherwise "minor". LLM consumers can sort/prioritize without
     * re-deriving the math.
     */
    private static function severity(int|float $value, int|float $limit): string
    {
        return $value > 2 * $limit ? 'critical' : 'minor';
    }

    /**
     * @param list<array{start: float, end: float, lines: list<string>}> $blocks
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
        array $blocks,
        ?float $maxCps = null,
        ?int $maxCpl = null,
        ?int $maxLines = null
    ): array {
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
}