<?php

use PHPUnit\Framework\TestCase;
use SrtValidator\ReadabilityProfile;

/**
 * Unit tests for per-language readability profile resolution from
 * resources/readability-profiles.json (shipped file + custom files).
 */
class ReadabilityProfileTest extends TestCase
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

    private function tempProfile(string $json): string
    {
        $path = tempnam(sys_get_temp_dir(), 'readability_profile_') . '.json';
        file_put_contents($path, $json);
        $this->tempFiles[] = $path;

        return $path;
    }

    public function testNullAndUnknownLanguagesUseDefault(): void
    {
        $default = ['cps' => 20.0, 'cpl' => 42, 'lines' => 2];

        $this->assertSame($default, ReadabilityProfile::for(null));
        $this->assertSame($default, ReadabilityProfile::for('de'));
        $this->assertSame($default, ReadabilityProfile::for('pt-BR'));
    }

    public function testChineseAndJapaneseProfilesFromShippedFile(): void
    {
        $this->assertSame(['cps' => 9.0, 'cpl' => 16, 'lines' => 2], ReadabilityProfile::for('zh'));
        $this->assertSame(['cps' => 4.0, 'cpl' => 13, 'lines' => 2], ReadabilityProfile::for('ja'));
    }

    public function testCodesAreNormalizedAndMatchedMostSpecificFirst(): void
    {
        $zh = ['cps' => 9.0, 'cpl' => 16, 'lines' => 2];

        $this->assertSame($zh, ReadabilityProfile::for('zh'));
        $this->assertSame($zh, ReadabilityProfile::for('ZH'));
        $this->assertSame($zh, ReadabilityProfile::for('zh_CN'));
        $this->assertSame($zh, ReadabilityProfile::for('zh-Hant-TW'));
    }

    public function testIso639TwoAndThreeLetterCodesResolve(): void
    {
        $zh = ['cps' => 9.0, 'cpl' => 16, 'lines' => 2];

        $this->assertSame($zh, ReadabilityProfile::for('zho'));
        $this->assertSame($zh, ReadabilityProfile::for('chi'));
    }

    public function testUnparseableCodeFallsBackToDefault(): void
    {
        $default = ['cps' => 20.0, 'cpl' => 42, 'lines' => 2];

        $this->assertSame($default, ReadabilityProfile::for('qq-ZZ'));
    }

    public function testPartialEntriesMergeOverDefault(): void
    {
        $path = $this->tempProfile(
            '{"default": {"cps": 20.0, "cpl": 42, "lines": 2}, "languages": {"ko": {"cpl": 16}}}'
        );

        $this->assertSame(
            ['cps' => 20.0, 'cpl' => 16, 'lines' => 2],
            ReadabilityProfile::for('ko', $path)
        );
    }

    public function testMissingFileThrows(): void
    {
        $this->expectException(RuntimeException::class);
        ReadabilityProfile::for('de', '/nonexistent/profiles.json');
    }

    public function testInvalidJsonThrows(): void
    {
        $path = $this->tempProfile('{not json');

        $this->expectException(RuntimeException::class);
        ReadabilityProfile::for('de', $path);
    }

    public function testUnknownKeyThrows(): void
    {
        $path = $this->tempProfile(
            '{"default": {"cps": 20.0, "cpl": 42, "lines": 2}, "languages": {"ko": {"cpss": 16}}}'
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unknown key');
        ReadabilityProfile::for('ko', $path);
    }

    public function testIncompleteDefaultThrows(): void
    {
        $path = $this->tempProfile('{"default": {"cps": 20.0}}');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing key');
        ReadabilityProfile::for('de', $path);
    }
}
