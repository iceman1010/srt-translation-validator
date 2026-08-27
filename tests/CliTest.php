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

        file_put_contents($this->fixtures['original'], implode("\n", $enLines));
        file_put_contents($this->fixtures['translated'], implode("\n", $deLines));
        file_put_contents($this->fixtures['missing'], implode("\n", array_slice($deLines, 0, 1)));
        file_put_contents($this->fixtures['malformed'], "1\n00:00:01,000 --> 00:00:03,000\nHallo Welt\n\nBROKEN_LINE_WITHOUT_TIMESTAMP\nMehr Text\n\n");
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

    private function tc(int $seconds): string
    {
        return sprintf('%02d:%02d:%02d,000', 0, intdiv($seconds, 60), $seconds % 60);
    }

    private function execute(array $args): array
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->bin)
            . ' ' . implode(' ', array_map('escapeshellarg', $args))
            . ' 2>&1';
        exec($cmd, $output, $exitCode);
        return [$exitCode, implode("\n", $output)];
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
        $this->assertStringContainsString('MISSING PARTS', $output);
    }

    public function testMalformedFormatFails(): void
    {
        [$exit, $output] = $this->execute([$this->fixtures['original'], $this->fixtures['malformed'], '-l', 'de']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('INVALID FORMAT', $output);
    }

    public function testStrictToleranceOption(): void
    {
        [$exit, $output] = $this->execute([$this->fixtures['original'], $this->fixtures['translated'], '-l', 'de', '-t', '0.1']);
        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('Timestamp tolerance: 0.1s', $output);
    }
}