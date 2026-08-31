<?php

use PHPUnit\Framework\TestCase;

/**
 * End-to-end tests for the bin/srt-validator command.
 * Self-contained: generates its own fixtures, so it runs in CI even without
 * the local example files.
 */
class CliTest extends TestCase
{
    private $bin;
    private $tmp;
    private $fixtures = [];

    protected function setUp(): void
    {
        $this->bin = __DIR__ . '/../bin/srt-validator';
        $this->tmp = sys_get_temp_dir() . '/srt-validator-cli-test-' . uniqid();
        mkdir($this->tmp, 0700, true);

        $this->fixtures['original'] = $this->tmp . '/original.srt';
        $this->fixtures['translated'] = $this->tmp . '/translated.srt';
        $this->fixtures['missing'] = $this->tmp . '/missing.srt';
        $this->fixtures['malformed'] = $this->tmp . '/malformed.srt';
        $this->fixtures['merged'] = $this->tmp . '/merged.srt';
        $this->fixtures['drifted'] = $this->tmp . '/drifted.srt';
        $this->fixtures['scriptmix'] = $this->tmp . '/scriptmix.srt';
        $this->fixtures['cleanread'] = $this->tmp . '/clean-read.srt';
        $this->fixtures['threeline'] = $this->tmp . '/three-line.srt';

        $english = 'The quick brown fox jumps over the lazy dog near the river bank.';
        $german = 'Der schnelle braune Fuchs sprang über den faulen Hund am Flussufer.';

        $enLines = [];
        $deLines = [];
        for ($i = 1; $i <= 20; $i++) {
            $start = 2 * ($i - 1);
            $end = ($i * 2) - 1;
            $enLines[] = $i . "\n" . sprintf('%s --> %s', $this->tc($start), $this->tc($end)) . "\n" . $english . ' ' . $i . "\n";
            $deLines[] = $i . "\n" . sprintf('%s --> %s', $this->tc($start), $this->tc($end)) . "\n" . $german . ' ' . $i . "\n";
        }

        // DeepL-style merge: one caption removed from the middle; the
        // neighbouring cues keep their exact times.
        $mergedLines = array_merge(array_slice($deLines, 0, 9), array_slice($deLines, 10));

        // One caption shifted by +0.8s: too far for the 0.5s tolerance, but
        // not onto another cue's start time.
        $driftedLines = $deLines;
        $driftedLines[9] = 10 . "\n"
            . sprintf('%s --> %s', $this->tc(18.8), $this->tc(19.8)) . "\n"
            . $german . ' 10' . "\n";

        // German translation with a hallucinated Cyrillic run in cue 10.
        $scriptMixLines = $deLines;
        $scriptMixLines[9] = 10 . "\n"
            . sprintf('%s --> %s', $this->tc(18), $this->tc(19)) . "\n"
            . $german . ' Привет мир' . "\n";
        file_put_contents($this->fixtures['scriptmix'], implode("\n", $scriptMixLines));

        file_put_contents($this->fixtures['original'], implode("\n", $enLines));
        file_put_contents($this->fixtures['translated'], implode("\n", $deLines));
        file_put_contents($this->fixtures['missing'], implode("\n", array_slice($deLines, 0, 1)));
        file_put_contents($this->fixtures['malformed'], "1\n00:00:01,000 --> 00:00:03,000\nHallo Welt\n\nBROKEN_LINE_WITHOUT_TIMESTAMP\nMehr Text\n\n");
        file_put_contents($this->fixtures['merged'], implode("\n", $mergedLines));
        file_put_contents($this->fixtures['drifted'], implode("\n", $driftedLines));

        // Readability fixtures: a clean 3s-per-caption file and a 3-line
        // caption file flagged mainly for line count.
        $cleanRead = [];
        for ($i = 1; $i <= 3; $i++) {
            $start = 4 * ($i - 1);
            $cleanRead[] = $i . "\n" . sprintf('%s --> %s', $this->tc($start), $this->tc($start + 3)) . "\nA short readable line.\n";
        }
        file_put_contents($this->fixtures['cleanread'], implode("\n", $cleanRead));

        file_put_contents($this->fixtures['threeline'], "1\n"
            . sprintf('%s --> %s', $this->tc(0), $this->tc(4)) . "\n"
            . "Line one\nLine two\nLine three\n");
    }

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->tmp)) {
            rmdir($this->tmp);
        }
    }

    private function tc(float $seconds): string
    {
        $ms = (int)round(($seconds - (int)$seconds) * 1000);
        return sprintf('%02d:%02d:%02d,%03d', 0, intdiv((int)$seconds, 60), (int)$seconds % 60, $ms);
    }

    private function execute(array $args): array
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->bin)
            . ' ' . implode(' ', array_map('escapeshellarg', $args))
            . ' 2>&1';
        exec($cmd, $output, $exitCode);
        return [$exitCode, implode("\n", $output)];
    }

    public function testSourceLangFlagSkipsVerbatimGate(): void
    {
        // An English "translation" that is a verbatim copy of the English
        // source: fails as an untranslated copy, unless the source language
        // is declared equal to the target (same-language passthrough).
        $copy = $this->tmp . '/copy.srt';
        file_put_contents($copy, file_get_contents($this->fixtures['original']));
        try {
            [$exitFail, $outFail] = $this->execute([$this->fixtures['original'], $copy, '-l', 'en']);
            $this->assertSame(1, $exitFail, 'An unchanged copy must fail without a declared source language');
            $this->assertStringContainsString('UNTRANSLATED COPY', $outFail);

            [$exitPass, $outPass] = $this->execute([$this->fixtures['original'], $copy, '-l', 'en', '--source-lang=en']);
            $this->assertSame(0, $exitPass, 'A same-language passthrough must pass with --source-lang');
            $this->assertStringContainsString('SAME LANGUAGE PASSTHROUGH', $outPass);
            $this->assertStringContainsString('RESULT: PASSED', $outPass);
        } finally {
            @unlink($copy);
        }
    }

    public function testHelp(): void
    {
        [$exit, $output] = $this->execute(['--help']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Subtitle Translation Validator', $output);
        $this->assertStringContainsString('<original-file> <translation-file>', $output);
    }

    public function testVersion(): void
    {
        [$exit, $output] = $this->execute(['--version']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('srt-translation-validator v', $output);

        [$exitShort, $outputShort] = $this->execute(['-V']);
        $this->assertSame(0, $exitShort);
        $this->assertSame($output, $outputShort);
    }

    public function testUpdateRequiresPharBuild(): void
    {
        [$exit, $output] = $this->execute(['--update']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('--update can only be used with the PHAR build', $output);
    }

    public function testMissingArgumentsIsUsageError(): void
    {
        [$exit, $output] = $this->execute(['only-one.srt']);
        $this->assertSame(2, $exit);
        $this->assertStringContainsString('expected exactly two subtitle files', $output);
    }

    public function testUnknownOptionIsUsageError(): void
    {
        [$exit] = $this->execute(['--nonsense', 'a.srt', 'b.srt']);
        $this->assertSame(2, $exit);
    }

    public function testNonexistentFileIsError(): void
    {
        [$exit, $output] = $this->execute([$this->tmp . '/nope.srt', $this->fixtures['translated'], '-l', 'de']);
        $this->assertSame(2, $exit);
        $this->assertStringContainsString('does not exist or is not readable', $output);
    }

    public function testValidTranslationPasses(): void
    {
        [$exit, $output] = $this->execute([$this->fixtures['original'], $this->fixtures['translated'], '--lang=de']);
        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('Expected language:', $output);
        $this->assertStringContainsString('RESULT: PASSED', $output);
    }

    public function testAutoDetectedLanguage(): void
    {
        [$exit, $output] = $this->execute([$this->fixtures['original'], $this->fixtures['translated']]);
        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('Expected language:', $output);
    }

    public function testMissingCaptionFails(): void
    {
        [$exit, $output] = $this->execute([$this->fixtures['original'], $this->fixtures['missing'], '-l', 'de']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('RESULT: FAILED', $output);
        $this->assertStringContainsString('MISSING CAPTION', $output);
    }

    public function testMergedCaptionsPassWithWarning(): void
    {
        [$exit, $output] = $this->execute([$this->fixtures['original'], $this->fixtures['merged'], '-l', 'de']);
        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('RESULT: PASSED', $output);
        $this->assertStringContainsString('MERGED CAPTIONS [warning]', $output);
    }

    public function testDriftWithinRaisedThresholdPasses(): void
    {
        // 1 of 20 captions drifted (5%): above the default 2% threshold,
        // but tolerable with an explicit --max-drift-ratio.
        [$exit, $output] = $this->execute([
            $this->fixtures['original'], $this->fixtures['drifted'], '-l', 'de',
            '--max-drift-ratio=0.1',
        ]);
        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('RESULT: PASSED', $output);
        $this->assertStringContainsString('TIMESTAMP MISMATCH', $output);
    }

    public function testStrictFlagFailsOnErrors(): void
    {
        // Same raised threshold, but strict mode fails on the single
        // error-severity defect regardless of ratios.
        [$exit, $output] = $this->execute([
            $this->fixtures['original'], $this->fixtures['drifted'], '-l', 'de',
            '--max-drift-ratio=0.1', '--strict',
        ]);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('RESULT: FAILED', $output);
        $this->assertStringContainsString('strict mode', $output);
    }

    public function testInvalidRatioOptionIsUsageError(): void
    {
        [$exit] = $this->execute([$this->fixtures['original'], $this->fixtures['translated'], '-l', 'de', '--max-drift-ratio=1.5']);
        $this->assertSame(2, $exit);

        [$exit] = $this->execute([$this->fixtures['original'], $this->fixtures['translated'], '-l', 'de', '--max-loss-ratio=abc']);
        $this->assertSame(2, $exit);
    }

    public function testMalformedFormatFails(): void
    {
        [$exit, $output] = $this->execute([$this->fixtures['original'], $this->fixtures['malformed'], '-l', 'de']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('INVALID FORMAT', $output);
    }

    public function testMalformedSourcePassesWithWarning(): void
    {
        $bad = $this->tmp . '/bad-source.srt';
        file_put_contents($bad, "this is not a subtitle file at all\n");
        $this->fixtures[] = $bad;
        [$exit, $output] = $this->execute([$bad, $this->fixtures['translated'], '-l', 'de']);
        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('SOURCE PARSE FAILED', $output);
        $this->assertStringContainsString('RESULT: PASSED', $output);
    }

    public function testStrictToleranceOption(): void
    {
        [$exit, $output] = $this->execute([$this->fixtures['original'], $this->fixtures['translated'], '-l', 'de', '-t', '0.1']);
        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('Timestamp tolerance: 0.1s', $output);
    }

    public function testJsonValidOutput(): void
    {
        [$exit, $output] = $this->execute([$this->fixtures['original'], $this->fixtures['translated'], '-l', 'de', '--json']);
        $this->assertSame(0, $exit, $output);

        $data = json_decode($output, true);
        $this->assertIsArray($data, $output);
        $this->assertTrue($data['valid']);
        $this->assertSame('passed', $data['result']);
        $this->assertSame('de', $data['language']);
        $this->assertSame(0, $data['defect_count']);
        $this->assertSame(0, $data['error_count']);
        $this->assertSame(0, $data['warning_count']);
        $this->assertSame([], $data['defects']);
        $this->assertSame([], $data['defects_by_type']);

        $this->assertIsArray($data['quality']);
        $this->assertIsArray($data['quality']['ratios']);
        $this->assertIsArray($data['quality']['thresholds']);
        $this->assertSame([], $data['quality']['reasons']);
    }

    public function testJsonDefectsOutput(): void
    {
        [$exit, $output] = $this->execute([$this->fixtures['original'], $this->fixtures['missing'], '-l', 'de', '--json']);
        $this->assertSame(1, $exit, $output);

        $data = json_decode($output, true);
        $this->assertIsArray($data, $output);
        $this->assertFalse($data['valid']);
        $this->assertSame('failed', $data['result']);
        $this->assertGreaterThan(0, $data['defect_count']);
        $this->assertSame(count($data['defects']), $data['defect_count']);
        $this->assertGreaterThan(0, $data['error_count']);

        $counted = [];
        $errors = 0;
        foreach ($data['defects'] as $defect) {
            $this->assertNotEmpty($defect['type']);
            $this->assertNotEmpty($defect['message']);
            $this->assertContains($defect['severity'], ['error', 'warning']);
            if ($defect['severity'] === 'error') {
                $errors++;
            }
            $counted[$defect['type']] = ($counted[$defect['type']] ?? 0) + 1;
        }
        $this->assertEquals($counted, $data['defects_by_type']);
        $this->assertSame($errors, $data['error_count']);
    }

    public function testJsonErrorOutput(): void
    {
        [$exit, $output] = $this->execute([$this->fixtures['original'], $this->tmp . '/nope.srt', '-l', 'de', '--json']);
        $this->assertSame(2, $exit, $output);

        $data = json_decode($output, true);
        $this->assertIsArray($data, $output);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('does not exist or is not readable', $data['error']);
    }

    public function testScriptMixFailsWithZeroTolerance(): void
    {
        [$exit, $output] = $this->execute([$this->fixtures['original'], $this->fixtures['scriptmix'], '-l', 'de', '--json']);
        $this->assertSame(1, $exit, $output);

        $data = json_decode($output, true);
        $this->assertFalse($data['valid']);
        $this->assertArrayHasKey('unexpected_script', $data['defects_by_type']);
        $this->assertGreaterThan(0, $data['quality']['ratios']['unexpected_script']);
        // JSON round-trips 0.0 as int 0.
        $this->assertEquals(0, $data['quality']['thresholds']['unexpected_script']);
    }

    public function testScriptRatioOptionRelaxesVerdict(): void
    {
        [$exit, $output] = $this->execute([
            $this->fixtures['original'], $this->fixtures['scriptmix'], '-l', 'de',
            '--max-script-ratio', '1', '--json',
        ]);
        $this->assertSame(0, $exit, $output);

        $data = json_decode($output, true);
        $this->assertTrue($data['valid']);
        $this->assertArrayNotHasKey('unexpected_script', $data['defects_by_type']);
    }

    public function testJsonContainsReadabilityStats(): void
    {
        [$exit, $output] = $this->execute([$this->fixtures['original'], $this->fixtures['translated'], '-l', 'de', '--json']);
        $this->assertSame(0, $exit, $output);

        $data = json_decode($output, true);
        $readability = $data['quality']['readability'];
        $this->assertIsArray($readability);
        // Whole numbers round-trip through JSON as ints.
        $this->assertIsNumeric($readability['avg_cps']);
        $this->assertIsNumeric($readability['max_cps']);
        $this->assertIsInt($readability['max_cps_caption']);
        $this->assertIsInt($readability['max_cpl']);
        $this->assertIsInt($readability['max_cpl_caption']);
    }

    public function testReportPrintsReadabilityStats(): void
    {
        [$exit, $output] = $this->execute([$this->fixtures['original'], $this->fixtures['translated'], '-l', 'de']);
        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('reading speed:', $output);
        $this->assertStringContainsString('line length:', $output);
        $this->assertMatchesRegularExpression('/reading speed:\s+[\d.]+ cps avg, [\d.]+ cps max \(caption #\d+\)/', $output);
    }

    public function testReadabilityJsonListsProblemsAndFails(): void
    {
        // The translated fixture is 20 one-second captions of ~68 chars:
        // every one exceeds 20 cps and 42 chars/line.
        [$exit, $output] = $this->execute(['--readability', '--json', $this->fixtures['translated']]);
        $this->assertSame(1, $exit, $output);

        $data = json_decode($output, true);
        $this->assertSame('readability', $data['mode']);
        $this->assertSame(20, $data['captions']);
        $this->assertSame(20, $data['problem_count']);
        $this->assertArrayHasKey('reading_speed', $data['problems_by_type']);
        $this->assertArrayNotHasKey('line_count', $data['problems_by_type']);

        $problem = $data['problems'][0];
        $this->assertSame(1, $problem['caption']);
        $this->assertArrayHasKey('start_seconds', $problem);
        $this->assertArrayHasKey('end_seconds', $problem);
        $this->assertArrayHasKey('text', $problem);
        $this->assertArrayHasKey('issues', $problem);
        $this->assertSame(20, $data['thresholds']['max_cps']);
        $this->assertSame(42, $data['thresholds']['max_cpl']);
    }

    public function testReadabilityJsonReportsCleanFile(): void
    {
        [$exit, $output] = $this->execute(['--readability', '--json', $this->fixtures['cleanread']]);
        $this->assertSame(0, $exit, $output);

        $data = json_decode($output, true);
        $this->assertSame(0, $data['problem_count']);
        $this->assertSame([], $data['problems']);
        $this->assertSame([], $data['problems_by_type']);
        $this->assertSame(3, $data['captions']);
    }

    public function testReadabilityRequiresExactlyOneFile(): void
    {
        [$exit, $output] = $this->execute(['--readability', $this->fixtures['original'], $this->fixtures['translated']]);
        $this->assertSame(2, $exit);
        $this->assertStringContainsString('--readability expects exactly one subtitle file, got 2', $output);
    }

    public function testReadabilityHumanReport(): void
    {
        [$exit, $output] = $this->execute(['--readability', $this->fixtures['translated']]);
        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('Readability Audit', $output);
        $this->assertStringContainsString('Problematic captions (20)', $output);
        $this->assertStringContainsString('caption #1', $output);
        $this->assertStringContainsString('exceeds the 20.0 cps limit', $output);
        $this->assertStringContainsString('RESULT: 20 problematic caption(s) found', $output);
    }

    public function testReadabilityCleanFileHumanReport(): void
    {
        [$exit, $output] = $this->execute(['--readability', $this->fixtures['cleanread']]);
        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('No readability problems found.', $output);
        $this->assertStringContainsString('RESULT: READABLE', $output);
    }

    public function testReadabilityLineLimitOverride(): void
    {
        // Default max-lines=2 flags the 3-line caption; raising it passes.
        [$exit, $output] = $this->execute(['--readability', '--json', $this->fixtures['threeline']]);
        $this->assertSame(1, $exit, $output);
        $data = json_decode($output, true);
        $this->assertSame(1, $data['problem_count']);
        $this->assertSame(['line_count' => 1], $data['problems_by_type']);

        [$exitRaised, $outputRaised] = $this->execute(['--readability', '--json', '--max-lines', '3', $this->fixtures['threeline']]);
        $this->assertSame(0, $exitRaised, $outputRaised);
        $this->assertSame(0, json_decode($outputRaised, true)['problem_count']);
    }

    public function testReadabilityLimitTruncatesJson(): void
    {
        [$exit, $output] = $this->execute(['--readability', '--json', '--limit', '3', $this->fixtures['translated']]);
        $this->assertSame(1, $exit, $output);

        $data = json_decode($output, true);
        $this->assertSame(20, $data['problem_count']);
        $this->assertSame(3, $data['shown']);
        $this->assertTrue($data['truncated']);
        $this->assertCount(3, $data['problems']);
        // problems_by_type still summarizes every caption, not just the shown 3.
        $this->assertSame(['reading_speed' => 20, 'line_length' => 20], $data['problems_by_type']);
    }

    public function testReadabilityLimitAboveTotalIsNotTruncated(): void
    {
        [$exit, $output] = $this->execute(['--readability', '--json', '--limit', '100', $this->fixtures['translated']]);
        $this->assertSame(1, $exit, $output);

        $data = json_decode($output, true);
        $this->assertSame(20, $data['shown']);
        $this->assertFalse($data['truncated']);
        $this->assertCount(20, $data['problems']);
    }

    public function testReadabilityInvalidLimitIsUsageError(): void
    {
        [$exitZero] = $this->execute(['--readability', '--limit', '0', $this->fixtures['translated']]);
        $this->assertSame(2, $exitZero);

        [$exitLetters] = $this->execute(['--readability', '--limit', 'abc', $this->fixtures['translated']]);
        $this->assertSame(2, $exitLetters);
    }

    public function testReadabilityWorstFirstOrdersByReadingSpeed(): void
    {
        [$exit, $output] = $this->execute(['--readability', '--json', '--worst-first', $this->fixtures['translated']]);
        $this->assertSame(1, $exit, $output);

        $data = json_decode($output, true);
        $this->assertCount(20, $data['problems']);

        $cps = array_column($data['problems'], 'cps');
        for ($i = 1; $i < count($cps); $i++) {
            $this->assertGreaterThanOrEqual($cps[$i], $cps[$i - 1], 'caption ' . $data['problems'][$i]['caption'] . ' out of order');
        }
        $this->assertNotSame(1, $data['problems'][0]['caption']);
        $this->assertSame('critical', $data['problems'][0]['severity']);
        $this->assertSame('critical', $data['problems'][0]['issues'][0]['severity']);
    }

    public function testReadabilityHumanReportCarriesSeverity(): void
    {
        [$exit, $output] = $this->execute(['--readability', $this->fixtures['translated']]);
        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('[critical]', $output);
        $this->assertStringContainsString('critical - reading speed', $output);
    }
}