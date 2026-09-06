<?php

use PHPUnit\Framework\TestCase;
use SrtValidator\ReadabilityChecker;

/**
 * Unit tests for the --readability audit's per-caption analysis.
 * Self-contained: builds caption blocks directly, no files, no subtitles lib.
 */
class ReadabilityCheckerTest extends TestCase
{
    private function block(float $start, float $end, array $lines): array
    {
        return ['start' => $start, 'end' => $end, 'lines' => $lines];
    }

    public function testCleanCaptionsReportNoProblems(): void
    {
        $analysis = (new ReadabilityChecker())->analyze([
            $this->block(0, 3, ['A short readable line.']),
            $this->block(4, 7, ['Another short line.']),
        ]);

        $this->assertSame(2, $analysis['captions']);
        $this->assertSame(2, $analysis['analyzed']);
        $this->assertSame([], $analysis['problems']);
        $this->assertSame([], $analysis['problems_by_type']);
    }

    public function testFastCaptionIsFlaggedForReadingSpeed(): void
    {
        // 80 chars over 2s = 40 cps.
        $analysis = (new ReadabilityChecker())->analyze([
            $this->block(0, 2, [str_repeat('a', 80)]),
        ]);

        $problems = $analysis['problems'];
        $this->assertCount(1, $problems);
        $this->assertSame(1, $problems[0]['caption']);
        $this->assertSame(40.0, $problems[0]['cps']);
        $this->assertSame(80, $problems[0]['chars']);

        $issue = $problems[0]['issues'][0];
        $this->assertSame('reading_speed', $issue['type']);
        $this->assertSame(40.0, $issue['value']);
        $this->assertSame(20.0, $issue['limit']);
        $this->assertSame(['reading_speed' => 1, 'line_length' => 1], $analysis['problems_by_type']);
    }

    public function testCriticalWhenValueExceedsTwiceTheLimit(): void
    {
        // 60 chars over 1s = 60 cps > 2 * 20 = 40: critical.
        $analysis = (new ReadabilityChecker())->analyze([
            $this->block(0, 1, [str_repeat('a', 60)]),
        ]);

        $problem = $analysis['problems'][0];
        $this->assertSame('critical', $problem['severity']);
        $this->assertSame('critical', $problem['issues'][0]['severity']);
    }

    public function testProblemSeverityRollsUpToCritical(): void
    {
        // A minor line-length issue (50 <= 84) alongside a critical reading
        // speed (50 cps > 40) must make the whole caption critical.
        $analysis = (new ReadabilityChecker())->analyze([
            $this->block(0, 1, [str_repeat('a', 50)]),
        ]);

        $problem = $analysis['problems'][0];
        $this->assertSame(50.0, $problem['cps']);
        $this->assertSame('critical', $problem['severity']);
        $severities = array_map(static fn ($i) => $i['severity'], $problem['issues']);
        sort($severities);
        $this->assertSame(['critical', 'minor'], $severities);
    }

    public function testLongLineIsFlaggedEvenWhenReadingSpeedIsFine(): void
    {
        // 50 chars over 5s = 10 cps, but 50 > 42 chars per line. Minor:
        // 50 is not beyond twice the 42 limit.
        $analysis = (new ReadabilityChecker())->analyze([
            $this->block(0, 5, [str_repeat('a', 50)]),
        ]);

        $issue = $analysis['problems'][0]['issues'][0];
        $this->assertSame('line_length', $issue['type']);
        $this->assertSame(50, $issue['value']);
        $this->assertSame(42, $issue['limit']);
        $this->assertSame('minor', $issue['severity']);
        $this->assertSame('minor', $analysis['problems'][0]['severity']);
    }

    public function testThreeLineCaptionIsFlaggedForLineCount(): void
    {
        $analysis = (new ReadabilityChecker())->analyze([
            $this->block(0, 10, ['one', 'two', 'three']),
        ]);

        $issue = $analysis['problems'][0]['issues'][0];
        $this->assertSame('line_count', $issue['type']);
        $this->assertSame(3, $issue['value']);
        $this->assertSame(2, $issue['limit']);
        $this->assertSame('minor', $issue['severity']);
    }

    public function testOneCaptionCanHaveMultipleIssues(): void
    {
        // One line of 45 chars over 2s: 22.5 cps AND line length 45.
        $analysis = (new ReadabilityChecker())->analyze([
            $this->block(1.5, 3.5, [str_repeat('a', 45)]),
        ]);

        $problem = $analysis['problems'][0];
        $this->assertCount(2, $problem['issues']);
        $this->assertSame('reading_speed', $problem['issues'][0]['type']);
        $this->assertSame('line_length', $problem['issues'][1]['type']);
        $this->assertSame(['reading_speed' => 1, 'line_length' => 1], $analysis['problems_by_type']);
    }

    public function testProblemCarriesTimecodesAndText(): void
    {
        $analysis = (new ReadabilityChecker())->analyze([
            $this->block(1.5, 3.5, [str_repeat('a', 45)]),
        ]);

        $problem = $analysis['problems'][0];
        $this->assertSame(1.5, $problem['start_seconds']);
        $this->assertSame(3.5, $problem['end_seconds']);
        $this->assertSame(2.0, $problem['duration_seconds']);
        $this->assertSame([str_repeat('a', 45)], $problem['lines']);
        $this->assertSame(str_repeat('a', 45), $problem['text']);
    }

    public function testVeryShortCueIsExcludedFromCpsStats(): void
    {
        // Under the 0.2s minimum: cps must stay null and no speed issue.
        $analysis = (new ReadabilityChecker())->analyze([
            $this->block(0, 0.1, ['short']),
        ]);

        $this->assertSame(0, $analysis['analyzed']);
        $this->assertSame([], $analysis['problems']);
    }

    public function testThresholdOverridesChangeLimitsAndResults(): void
    {
        // 60 chars over 3s = 20 cps: fine against the default 20.0, but
        // flagged when the limit is tightened to 15.
        $strict = (new ReadabilityChecker())->analyze(
            [$this->block(0, 3, [str_repeat('a', 60)])],
            15.0,
            42,
            2
        );
        $this->assertCount(1, $strict['problems']);
        $this->assertSame(15.0, $strict['problems'][0]['issues'][0]['limit']);
        $this->assertSame(['max_cps' => 15.0, 'max_cpl' => 42, 'max_lines' => 2], $strict['thresholds']);

        $lenient = (new ReadabilityChecker())->analyze(
            [$this->block(0, 10, ['one', 'two', 'three'])],
            20.0,
            42,
            3
        );
        $this->assertSame([], $lenient['problems']);
    }

    public function testStatsTrackMaximumsAndCaptionNumbers(): void
    {
        $analysis = (new ReadabilityChecker())->analyze([
            $this->block(0, 2, [str_repeat('a', 80)]),   // 40 cps, 80-char line
            $this->block(4, 8, ['A perfectly reasonable line.']),
        ]);

        $this->assertSame(40.0, $analysis['max_cps']);
        $this->assertSame(1, $analysis['max_cps_caption']);
        $this->assertSame(80, $analysis['max_cpl']);
        $this->assertSame(1, $analysis['max_cpl_caption']);
        $this->assertLessThan(40.0, $analysis['avg_cps']);
    }

    /** Path of the per-test temporary subtitle file (removed in tearDown). */
    private $tempFile;

    protected function tearDown(): void
    {
        if ($this->tempFile !== null && is_file($this->tempFile)) {
            unlink($this->tempFile);
        }
    }

    /** Two-cue SRT: cue 1 is 80 chars over 1s (80 cps, critical), cue 2 clean. */
    private function writeTempSrt(): string
    {
        $this->tempFile = tempnam(sys_get_temp_dir(), 'rc-test');
        file_put_contents(
            $this->tempFile,
            "1\n00:00:01,000 --> 00:00:02,000\n" . str_repeat('a', 80) . "\n\n"
            . "2\n00:00:04,000 --> 00:00:08,000\nA perfectly reasonable line.\n"
        );

        return $this->tempFile;
    }

    public function testConstructorBindsFileForArgumentlessAnalyze(): void
    {
        $analysis = (new ReadabilityChecker($this->writeTempSrt()))->analyze();

        $this->assertSame(2, $analysis['captions']);
        $this->assertCount(1, $analysis['problems']);
        $this->assertSame(80.0, $analysis['problems'][0]['cps']);
        $this->assertSame('critical', $analysis['problems'][0]['severity']);
    }

    public function testAnalyzeFileOneShotMatchesConstructorPath(): void
    {
        $file = $this->writeTempSrt();

        $viaConstructor = (new ReadabilityChecker($file))->analyze();
        $viaMethod = (new ReadabilityChecker())->analyzeFile($file);

        $this->assertSame($viaConstructor, $viaMethod);
    }

    public function testAnalyzeContentRunsOnInMemorySrt(): void
    {
        $content = "1\n00:00:01,000 --> 00:00:02,000\n" . str_repeat('a', 80) . "\n";
        $analysis = (new ReadabilityChecker())->analyzeContent($content);

        $this->assertSame(1, $analysis['captions']);
        $this->assertCount(1, $analysis['problems']);
        $this->assertSame('reading_speed', $analysis['problems'][0]['issues'][0]['type']);
    }

    public function testAnalyzeFilePassesLimitOverrides(): void
    {
        $analysis = (new ReadabilityChecker())->analyzeFile($this->writeTempSrt(), 15.0, 37, 3);

        $this->assertSame(['max_cps' => 15.0, 'max_cpl' => 37, 'max_lines' => 3], $analysis['thresholds']);
        $this->assertSame('reading_speed', $analysis['problems'][0]['issues'][0]['type']);
    }

    public function testConstructorThrowsForMissingFile(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ReadabilityChecker('/nonexistent/path.srt');
    }

    public function testAnalyzeFileThrowsForMissingFile(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ReadabilityChecker())->analyzeFile('/nonexistent/path.srt');
    }

    public function testAnalyzeWithoutBlocksOrFileThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ReadabilityChecker())->analyze();
    }
}