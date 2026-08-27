<?php

namespace SrtValidator;

/**
 * Regex-based validator for SRT (SubRip) and WebVTT subtitle files.
 *
 * Unlike tolerant subtitle parsers it does not try to "fix" malformed input:
 * it validates the structural grammar of every block and reports defects with
 * exact line numbers. It intentionally accepts every widespread variant of
 * both formats so it does not produce false positives on real-world files.
 *
 * Supported SRT variants:
 *   - comma (",") or dot (".") millisecond separator
 *   - sequence number on its own line, or on the same line as the timing
 *   - timing lines without a sequence number
 *   - optional X1:X2:Y1:Y2 coordinate extensions after the end timestamp
 *   - LF / CRLF / CR line endings and an optional UTF-8 BOM
 *   - omitted trailing blank line / extra blank lines between blocks
 *
 * Supported WebVTT variants:
 *   - optional BOM, mandatory "WEBVTT" header line
 *   - MM:SS.mmm or HH:MM:SS.mmm timestamps (hours optional)
 *   - optional cue identifiers, cue settings (align/position/line/size/region)
 *   - NOTE comment blocks, STYLE and REGION blocks
 *   - negative timestamps (used for pre-roll content by some tools)
 */
class SubtitleFormatValidator
{
    public const FORMAT_SRT = 'srt';
    public const FORMAT_VTT = 'vtt';

    /**
     * SRT timing line.
     * Optional leading sequence number on the same line, HH:MM:SS with a
     * comma or period millisecond separator, " --> " arrow. Anything after
     * the end timestamp (e.g. X1:X2:Y1:Y2 coordinate extensions) is accepted
     * via a tolerant single-group tail, matching how production parsers
     * (cdown/srt, OmniVoice) handle it. Minutes/seconds tolerate 1-2 digits.
     */
    public const SRT_TIMING_RE = '/^(?:(?<num>\d+)\s+)?(?<sh>\d{1,3}):(?<sm>\d{1,2}):(?<ss>\d{1,2})[,.]\d{1,3}\s*-->\s*(?<eh>\d{1,3}):(?<em>\d{1,2}):(?<es>\d{1,2})[,.]\d{1,3}(?:\s.*)?$/';

    /**
     * WebVTT cue timings.
     * Hours are optional (2+ digits), minutes/seconds are 0-59, exactly three
     * millisecond digits after a period, optional negative start, optional
     * whitespace-separated name:value cue settings after the end timestamp.
     */
    public const VTT_TIMING_RE = '/^\s*(?:(?<sh>-?\d{2,}):)?(?<sm>[0-5]\d):(?<ss>[0-5]\d)\.\d{3}\s*-->\s*(?:(?<eh>-?\d{2,}):)?(?<em>[0-5]\d):(?<es>[0-5]\d)\.\d{3}(?:\s+\S+:\S+)*\s*$/';

    /**
     * Validates a subtitle file on disk.
     *
     * @return array{format: ?string, valid: bool, errors: array, warnings: array, cue_count: int}
     */
    public function validateFile(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return $this->result(null, false, [
                ['line' => 0, 'subtype' => 'unreadable_file', 'message' => "Unable to read subtitle file: {$path}"]
            ], [], 0);
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return $this->result(null, false, [
                ['line' => 0, 'subtype' => 'unreadable_file', 'message' => "Unable to read subtitle file: {$path}"]
            ], [], 0);
        }

        return $this->validateContent($content, pathinfo($path, PATHINFO_EXTENSION));
    }

    /**
     * Detects the subtitle format from raw content.
     */
    public function detectFormat(string $content): ?string
    {
        $lines = $this->splitLines($this->stripBom($content));

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            if (preg_match('/^WEBVTT(?:[ \t]+.*)?$/', trim($line))) {
                return self::FORMAT_VTT;
            }
            return self::FORMAT_SRT;
        }

        return null;
    }

    /**
     * Validates subtitle content, optionally cross-checked against the file
     * extension so .srt files containing WebVTT are flagged.
     *
     * @return array{format: ?string, valid: bool, errors: array, warnings: array, cue_count: int}
     */
    public function validateContent(string $content, string $extension = ''): array
    {
        $content = $this->stripBom($content);

        if (strpos($content, "\x00") !== false) {
            return $this->result(null, false, [
                ['line' => 0, 'subtype' => 'binary_content', 'message' => 'File contains binary NUL bytes and is not a valid text subtitle file']
            ], [], 0);
        }

        if (trim($content) === '') {
            return $this->result(null, false, [
                ['line' => 1, 'subtype' => 'empty_file', 'message' => 'File is empty']
            ], [], 0);
        }

        $lines = $this->splitLines($content);
        $errors = [];
        $warnings = [];

        $format = $this->detectFormat($content);
        $extLower = strtolower($extension);
        if ($format === self::FORMAT_VTT) {
            $cueCount = $this->validateVtt($lines, $errors, $warnings);
        } elseif (in_array($extLower, ['vtt', 'webvtt'], true)) {
            // A file claiming to be WebVTT is validated as such unless its
            // timings are unmistakably SRT (comma millisecond separator).
            // Without this, a .vtt file missing its WEBVTT header would
            // silently pass as SRT instead of reporting the defect.
            if ($this->looksLikeSrtTimings($lines)) {
                $format = self::FORMAT_SRT;
                $cueCount = $this->validateSrt($lines, $errors, $warnings);
            } else {
                $format = self::FORMAT_VTT;
                $cueCount = $this->validateVtt($lines, $errors, $warnings);
            }
        } else {
            $format = self::FORMAT_SRT;
            $cueCount = $this->validateSrt($lines, $errors, $warnings);
        }

        if ($extension !== '') {
            $allowed = $format === self::FORMAT_SRT ? ['srt'] : ['vtt', 'webvtt'];
            if (!in_array(strtolower($extension), $allowed, true)) {
                $warnings[] = [
                    'line' => 1,
                    'subtype' => 'extension_mismatch',
                    'message' => "File content looks like {$format} but the file extension is .{$extension}"
                ];
            }
        }

        return $this->result($format, empty($errors), $errors, $warnings, $cueCount);
    }

    private function result(?string $format, bool $valid, array $errors, array $warnings, int $cueCount): array
    {
        return [
            'format' => $format,
            'valid' => $valid,
            'errors' => $errors,
            'warnings' => $warnings,
            'cue_count' => $cueCount,
        ];
    }

    /**
     * State-machine SRT validation. Returns the number of cues found.
     */
    private function validateSrt(array $lines, array &$errors, array &$warnings): int
    {
        $n = count($lines);
        $i = 0;
        $cueCount = 0;

        $inCue = false;          // a valid timing line opened the current block
        $broken = false;         // current block already reported a structural error
        $cueTextLines = 0;
        $timingLine = null;
        $pendingNumber = null;
        $pendingNumberLine = null;
        $lastNumber = null;

        while ($i < $n) {
            $raw = $lines[$i];
            $line = trim($raw);
            $lineNo = $i + 1;

            // Blank line terminates the current block.
            if ($line === '') {
                $this->flushSrtCue($cueCount, $inCue, $broken, $cueTextLines, $timingLine, $pendingNumber, $pendingNumberLine, $lastNumber, $warnings);
                $i++;
                continue;
            }

            // Any arrow marker -> a timing line is expected right here
            // (either valid per the regex, or malformed -> structural defect).
            if ($this->containsArrow($raw)) {
                if (preg_match(self::SRT_TIMING_RE, $line, $m)) {
                    if ($inCue && $cueTextLines === 0) {
                        $warnings[] = [
                            'line' => $timingLine,
                            'subtype' => 'empty_cue',
                            'message' => "Subtitle block starting at line {$timingLine} has no text"
                        ];
                    }
                    $inCue = true;
                    $broken = false;
                    $cueTextLines = 0;
                    $timingLine = $lineNo;

                    if (!empty($m['num'])) {
                        $pendingNumber = (int)$m['num'];
                        $pendingNumberLine = $lineNo;
                    }

                    $this->warnRange($m['sh'], $m['sm'], $m['ss'], $m['eh'], $m['em'], $m['es'], $lineNo, $warnings);
                    $this->warnOrder(
                        $this->toSeconds((int)$m['sh'], (int)$m['sm'], (int)$m['ss'], 0),
                        $this->toSeconds((int)$m['eh'], (int)$m['em'], (int)$m['es'], 0),
                        true,
                        $lineNo,
                        $warnings
                    );
                    $i++;
                    continue;
                }
                $errors[] = [
                    'line' => $lineNo,
                    'subtype' => 'malformed_timestamp',
                    'message' => "Line {$lineNo}: malformed timing line: " . $this->preview($line)
                ];
                $broken = true;
                $inCue = false;
                $cueTextLines = 0;
                $i++;
                continue;
            }

            // A bare integer alone on its line is a sequence number only when
            // the next non-blank line carries the arrow.
            if (preg_match('/^\d+$/', $line)) {
                $next = $this->nextNonEmptyIndex($lines, $i);
                if (!$inCue && $next !== null && $this->containsArrow($lines[$next])) {
                    $pendingNumber = (int)$line;
                    $pendingNumberLine = $lineNo;
                    $i++;
                    continue;
                }
                // A bare number at the start of a block whose next line is not
                // a timing line: report the missing timestamp where it should
                // have been (the following line) and absorb the block.
                if (!$inCue && !$broken && $next !== null) {
                    $errorOffset = $next + 1;
                    $errors[] = [
                        'line' => $errorOffset,
                        'subtype' => 'missing_timestamp',
                        'message' => "Line {$errorOffset}: expected a timing line (HH:MM:SS[,.]mmm --> HH:MM:SS[,.]mmm) after sequence number {$line} but found: " . $this->preview($lines[$next])
                    ];
                    $broken = true;
                    $inCue = true;
                    $cueTextLines = 1;
                    $i++;
                    continue;
                }
                // otherwise fall through -> treated as subtitle text
            }

            // Subtitle text line.
            if ($inCue) {
                $cueTextLines++;
            } else {
                if (!$broken) {
                    if ($pendingNumber !== null) {
                        $errors[] = [
                            'line' => $pendingNumberLine,
                            'subtype' => 'missing_timestamp',
                            'message' => "Line {$pendingNumberLine}: sequence number {$pendingNumber} is not followed by a timing line"
                        ];
                        $pendingNumber = null;
                        $pendingNumberLine = null;
                    } else {
                        $errors[] = [
                            'line' => $lineNo,
                            'subtype' => 'missing_timestamp',
                            'message' => "Line {$lineNo}: expected a timing line (HH:MM:SS[,.]mmm --> HH:MM:SS[,.]mmm) but found text: " . $this->preview($line)
                        ];
                    }
                    $broken = true;
                }
                // Absorb following text lines into this block so we report once.
                $inCue = true;
                $cueTextLines = 1;
            }
            $i++;
        }

        $this->flushSrtCue($cueCount, $inCue, $broken, $cueTextLines, $timingLine, $pendingNumber, $pendingNumberLine, $lastNumber, $warnings);

        return $cueCount;
    }

    private function flushSrtCue(
        int &$cueCount,
        bool &$inCue,
        bool &$broken,
        int &$cueTextLines,
        ?int &$timingLine,
        ?int &$pendingNumber,
        ?int &$pendingNumberLine,
        ?int &$lastNumber,
        array &$warnings
    ): void {
        if (!$inCue) {
            $broken = false;
            return;
        }

        if ($cueTextLines === 0) {
            $warnings[] = [
                'line' => $timingLine,
                'subtype' => 'empty_cue',
                'message' => "Subtitle block starting at line {$timingLine} has no text"
            ];
        }

        if ($pendingNumber !== null) {
            if ($lastNumber !== null && $pendingNumber < $lastNumber) {
                $warnings[] = [
                    'line' => $pendingNumberLine,
                    'subtype' => 'out_of_order_numbering',
                    'message' => "Sequence number {$pendingNumber} (line {$pendingNumberLine}) is out of order after sequence number {$lastNumber}"
                ];
            } elseif ($lastNumber !== null && $pendingNumber === $lastNumber) {
                $warnings[] = [
                    'line' => $pendingNumberLine,
                    'subtype' => 'duplicate_numbering',
                    'message' => "Duplicate sequence number {$pendingNumber} on line {$pendingNumberLine}"
                ];
            }
            $lastNumber = $pendingNumber;
        }

        $cueCount++;
        $inCue = false;
        $broken = false;
        $cueTextLines = 0;
        $timingLine = null;
        $pendingNumber = null;
        $pendingNumberLine = null;
    }

    /**
     * State-machine WebVTT validation. Returns the number of cues found.
     */
    private function validateVtt(array $lines, array &$errors, array &$warnings): int
    {
        $n = count($lines);
        $i = 0;

        // Optional leading blank lines are tolerated.
        while ($i < $n && trim($lines[$i]) === '') {
            $i++;
        }

        if ($i < $n && !preg_match('/^WEBVTT(?:[ \t]+.*)?$/', trim($lines[$i]))) {
            $errors[] = [
                'line' => $i + 1,
                'subtype' => 'missing_webvtt_header',
                'message' => 'Line ' . ($i + 1) . ': WebVTT file must start with the "WEBVTT" header line: ' . $this->preview(trim($lines[$i]))
            ];
        }
        if ($i < $n) {
            $i++;
        }

        $cueCount = 0;
        $inCue = false;
        $broken = false;
        $cueTextLines = 0;
        $timingLine = null;
        $lastStartSeconds = null;

        while ($i < $n) {
            $raw = $lines[$i];
            $line = trim($raw);
            $lineNo = $i + 1;

            if ($line === '') {
                if ($inCue && $cueTextLines === 0) {
                    $warnings[] = [
                        'line' => $timingLine,
                        'subtype' => 'empty_cue',
                        'message' => "WebVTT cue starting at line {$timingLine} has no payload text"
                    ];
                }
                $inCue = false;
                $broken = false;
                $cueTextLines = 0;
                $i++;
                continue;
            }

            // NOTE comment blocks and STYLE/REGION definition blocks run until
            // the blank line. They are allowed interleaved with cues.
            if (preg_match('/^NOTE(?:\s+|$)/', $line)) {
                $i++;
                while ($i < $n && trim($lines[$i]) !== '') {
                    $i++;
                }
                continue;
            }
            if (preg_match('/^STYLE(?:\s*)$/', $line)) {
                $i++;
                while ($i < $n && trim($lines[$i]) !== '') {
                    $i++;
                }
                continue;
            }
            if (preg_match('/^REGION(?:\s*)$/', $line)) {
                $i++;
                while ($i < $n && trim($lines[$i]) !== '') {
                    $i++;
                }
                continue;
            }

            // Cue timings line (or a malformed one).
            if ($this->containsArrow($raw)) {
                if (preg_match(self::VTT_TIMING_RE, $line, $m)) {
                    if ($inCue && $cueTextLines === 0) {
                        $warnings[] = [
                            'line' => $timingLine,
                            'subtype' => 'empty_cue',
                            'message' => "WebVTT cue starting at line {$timingLine} has no payload text"
                        ];
                    }
                    $inCue = true;
                    $broken = false;
                    $cueTextLines = 0;
                    $timingLine = $lineNo;
                    $cueCount++;

                    $start = $this->toSeconds((int)$m['sh'], (int)$m['sm'], (int)$m['ss'], 0);
                    $end = $this->toSeconds((int)$m['eh'], (int)$m['em'], (int)$m['es'], 0);
                    if ($end < $start) {
                        $warnings[] = [
                            'line' => $lineNo,
                            'subtype' => 'reversed_timing',
                            'message' => "WebVTT cue on line {$lineNo} ends before it starts (end < start)"
                        ];
                    }
                    if ($start !== null && $lastStartSeconds !== null && $start < $lastStartSeconds) {
                        $warnings[] = [
                            'line' => $lineNo,
                            'subtype' => 'start_time_regression',
                            'message' => "WebVTT cue on line {$lineNo} starts before the previous cue's start time"
                        ];
                    }
                    $lastStartSeconds = $start;
                    $i++;
                    continue;
                }
                $errors[] = [
                    'line' => $lineNo,
                    'subtype' => 'malformed_timestamp',
                    'message' => "Line {$lineNo}: malformed WebVTT cue timings: " . $this->preview($line)
                ];
                $broken = true;
                $inCue = false;
                $cueTextLines = 0;
                $i++;
                continue;
            }

            // No arrow -> either a cue identifier (next non-blank must be a
            // timings line) or stray text.
            $next = $this->nextNonEmptyIndex($lines, $i);
            if ($inCue) {
                $cueTextLines++;
            } elseif ($next !== null && $this->containsArrow($lines[$next])) {
                // valid cue identifier: the next iteration handles the timings
            } else {
                if (!$broken) {
                    $errors[] = [
                        'line' => $lineNo,
                        'subtype' => 'stray_text',
                        'message' => "Line {$lineNo}: text is not part of a WebVTT cue (expected a cue identifier followed by timings): " . $this->preview($line)
                    ];
                    $broken = true;
                }
                $inCue = true;
                $cueTextLines = 1;
            }
            $i++;
        }

        if ($inCue && $cueTextLines === 0) {
            $warnings[] = [
                'line' => $timingLine,
                'subtype' => 'empty_cue',
                'message' => "WebVTT cue starting at line {$timingLine} has no payload text"
            ];
        }

        return $cueCount;
    }

    private function containsArrow(string $line): bool
    {
        return strpos($line, '-->') !== false
            || strpos($line, '->') !== false
            || preg_match('/\x{2192}|\x{2794}/u', $line) === 1;
    }

    /**
     * True when any timing line uses SRT's comma millisecond separator.
     * WebVTT only allows a period, so comma-ms timings are unmistakably SRT
     * even when the WEBVTT header is absent.
     */
    private function looksLikeSrtTimings(array $lines): bool
    {
        foreach ($lines as $line) {
            if ($this->containsArrow($line) && preg_match('/,\d{1,3}/', $line)) {
                return true;
            }
        }
        return false;
    }

    private function nextNonEmptyIndex(array $lines, int $from): ?int
    {
        $n = count($lines);
        for ($j = $from + 1; $j < $n; $j++) {
            if (trim($lines[$j]) !== '') {
                return $j;
            }
        }
        return null;
    }

    private function stripBom(string $content): string
    {
        if (strncmp($content, "\xEF\xBB\xBF", 3) === 0) {
            return substr($content, 3);
        }
        return $content;
    }

    private function splitLines(string $content): array
    {
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        return explode("\n", $content);
    }

    private function preview(string $line): string
    {
        $line = trim($line);
        return mb_strlen($line) > 60 ? mb_substr($line, 0, 60) . '...' : $line;
    }

    /**
     * Emits warnings when minutes/seconds exceed their valid range.
     */
    private function warnRange(string $sh, string $sm, string $ss, string $eh, string $em, string $es, int $lineNo, array &$warnings): void
    {
        $checks = [['minute', (int)$sm], ['second', (int)$ss], ['minute', (int)$em], ['second', (int)$es]];
        foreach ($checks as [$unit, $value]) {
            if ($value > 59) {
                $warnings[] = [
                    'line' => $lineNo,
                    'subtype' => 'timestamp_out_of_range',
                    'message' => "Line {$lineNo}: {$unit} value {$value} is out of range (max 59)"
                ];
            }
        }
    }

    /**
     * Emits warnings when end <= start. SRT additionally warns on zero duration.
     */
    private function warnOrder(float $start, float $end, bool $zeroIsSuspect, int $lineNo, array &$warnings): void
    {
        if ($end < $start) {
            $warnings[] = [
                'line' => $lineNo,
                'subtype' => 'reversed_timing',
                'message' => "Line {$lineNo}: subtitle ends before it starts (end < start)"
            ];
        } elseif ($zeroIsSuspect && $end === $start) {
            $warnings[] = [
                'line' => $lineNo,
                'subtype' => 'zero_duration',
                'message' => "Line {$lineNo}: subtitle has zero duration (start == end)"
            ];
        }
    }

    private function toSeconds(int $hours, int $minutes, int $seconds, int $milliseconds): float
    {
        return ($hours * 3600) + ($minutes * 60) + $seconds + ($milliseconds / 1000);
    }
}