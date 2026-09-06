<?php

use PHPUnit\Framework\TestCase;
use SrtValidator\SubtitleLoader;

/**
 * Unit tests for the dual-version (subtitles 1.x / 0.3.x) loader adapter.
 * Runs against whichever line is installed in vendor/ (1.x by default; the
 * CI matrix also runs it with 0.3.10 forced).
 */
class SubtitleLoaderTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->tempFiles = [];
    }

    private function tempFile(string $name, string $content): string
    {
        $path = sys_get_temp_dir() . '/' . uniqid('subtitle_loader_') . $name;
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return $path;
    }

    public function testLoadFileParsesSrt(): void
    {
        $path = $this->tempFile('sample.srt', "1\n00:00:01,000 --> 00:00:03,500\nHello\n\n2\n00:00:04,000 --> 00:00:06,000\nWorld\n");

        $blocks = SubtitleLoader::loadFile($path)->getInternalFormat();

        $this->assertCount(2, $blocks);
        // Loose equality: 1.x yields int seconds when ms are 000, 0.3.x float.
        $this->assertEquals(1, $blocks[0]['start']);
        $this->assertEquals(3.5, $blocks[0]['end']);
        $this->assertSame(['Hello'], $blocks[0]['lines']);
        $this->assertSame(['World'], $blocks[1]['lines']);
    }

    public function testLoadFileParsesVtt(): void
    {
        $path = $this->tempFile('sample.vtt', "WEBVTT\n\n00:00:01.000 --> 00:00:03.000\nHello\n");

        $blocks = SubtitleLoader::loadFile($path)->getInternalFormat();

        $this->assertCount(1, $blocks);
        $this->assertEquals(1, $blocks[0]['start']);
        $this->assertSame(['Hello'], $blocks[0]['lines']);
    }

    public function testLoadFileIgnoresWrongOrMissingExtension(): void
    {
        $vttContent = "WEBVTT\n\n00:00:01.000 --> 00:00:03.000\nHello\n";
        $noExt = $this->tempFile('sample', $vttContent);
        $wrongExt = $this->tempFile('sample.txt', $vttContent);

        // 1.x detects by content natively; 0.3.x gets the sniffed format
        // passed explicitly - both parse the WebVTT payload either way.
        $this->assertCount(1, SubtitleLoader::loadFile($noExt)->getInternalFormat());
        $this->assertCount(1, SubtitleLoader::loadFile($wrongExt)->getInternalFormat());
    }

    public function testLoadStringParsesSrtAndVtt(): void
    {
        $srt = SubtitleLoader::loadString("1\n00:00:01,000 --> 00:00:02,000\nHi\n")->getInternalFormat();
        $vtt = SubtitleLoader::loadString("WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nHi\n")->getInternalFormat();

        $this->assertCount(1, $srt);
        $this->assertSame(['Hi'], $srt[0]['lines']);
        $this->assertCount(1, $vtt);
        $this->assertSame(['Hi'], $vtt[0]['lines']);
    }

    public function testLoadFileMissingFileThrows(): void
    {
        $this->expectException(Throwable::class);
        SubtitleLoader::loadFile('/nonexistent/path/sample.srt');
    }

    public function testDetectFormat(): void
    {
        $this->assertSame('vtt', SubtitleLoader::detectFormat("WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nHi\n"));
        $this->assertSame('vtt', SubtitleLoader::detectFormat("\xEF\xBB\xBFWEBVTT\n\n"));
        $this->assertSame('vtt', SubtitleLoader::detectFormat("WEBVTT - some header\n"));
        $this->assertSame('srt', SubtitleLoader::detectFormat("1\n00:00:01,000 --> 00:00:02,000\nHi\n"));
        $this->assertSame('srt', SubtitleLoader::detectFormat(''));
    }
}
