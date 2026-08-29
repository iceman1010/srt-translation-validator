<?php

use PHPUnit\Framework\TestCase;
use SrtValidator\SrtTranslationValidator;

/**
 * Script-mismatch detection (unexpected_script) and readability statistics.
 * Self-contained: generates its own fixtures, so it runs without the local
 * example files.
 */
class ScriptAndReadabilityTest extends TestCase
{
    private $validator;
    private $tmp;
    private $fixtures = [];

    protected function setUp(): void
    {
        $this->validator = new SrtTranslationValidator();
        $this->tmp = sys_get_temp_dir() . '/srt-validator-script-test-' . uniqid();
        mkdir($this->tmp, 0700, true);
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

    public function testCyrillicInHungarianFails(): void
    {
        $original = $this->write('original', $this->englishCues());
        $translation = $this->write('scriptmix', $this->cuesWithLines([
            ['Ez egy magyar mondat, minden rendben.', 'Привет мир'],
            ['Mindenki a helyére ül vissza.', 'és még valami történt'],
            ['Ez az utolsó mondat a felvételen.'],
        ]));

        $result = $this->validator->validate($original, $translation, 'hu');

        $this->assertFalse($result['valid'], 'Cyrillic letters in a Hungarian translation must fail');
        $defects = array_filter($result['defects'], fn ($d) => $d['type'] === 'unexpected_script');
        $this->assertNotEmpty($defects);

        $defect = reset($defects);
        $this->assertSame('error', $defect['severity']);
        $this->assertSame(9, $defect['foreign_chars'], 'Привет (6) + мир (3) Cyrillic letters');
        $this->assertArrayHasKey('cyrillic', $defect['scripts']);
        $this->assertGreaterThan(0, $result['quality']['ratios']['unexpected_script']);
        $this->assertStringContainsString('unexpected script', implode(' ', $result['quality']['reasons']));
    }

    public function testCleanHungarianHasNoScriptDefect(): void
    {
        $original = $this->write('original', $this->englishCues());
        $translation = $this->write('clean', $this->cuesWithLines([
            ['Ez egy magyar mondat, minden rendben.'],
            ['Mindenki a helyére ül vissza, és vár.'],
            ['Ez az utolsó mondat a felvételen.'],
        ]));

        $result = $this->validator->validate($original, $translation, 'hu');

        $this->assertTrue($result['valid']);
        $this->assertSame(0.0, $result['quality']['ratios']['unexpected_script']);
        $this->assertSame(0.0, $result['quality']['thresholds']['unexpected_script']);
        $this->assertCount(0, array_filter($result['defects'], fn ($d) => $d['type'] === 'unexpected_script'));
    }

    public function testHanIsAllowedInChinese(): void
    {
        $original = $this->write('original', $this->englishCues());
        $translation = $this->write('chinese', $this->cuesWithLines([
            ['这是一句中文的台词，一切正常。'],
            ['所有人都回到了自己的座位上。'],
            ['这是录音里的最后一句话。'],
        ]));

        $result = $this->validator->validate($original, $translation, 'zh');

        $this->assertTrue($result['valid']);
        $this->assertSame(0.0, $result['quality']['ratios']['unexpected_script']);
    }

    public function testKanaAndKanjiAreAllowedInJapanese(): void
    {
        $original = $this->write('original', $this->englishCues());
        $translation = $this->write('japanese', $this->cuesWithLines([
            ['これは日本語のセリフです。'],
            ['ひらがなとカタカナと漢字が混ざっています。'],
            ['これが最後の録音です。'],
        ]));

        $result = $this->validator->validate($original, $translation, 'ja');

        $this->assertTrue($result['valid']);
        $this->assertSame(0.0, $result['quality']['ratios']['unexpected_script']);
    }

    public function testHangulIsAllowedInKorean(): void
    {
        $original = $this->write('original', $this->englishCues());
        $translation = $this->write('korean', $this->cuesWithLines([
            ['이것은 한국어 대사입니다.'],
            ['모두 자기 자리로 돌아갑니다.'],
            ['녹음의 마지막 문장입니다.'],
        ]));

        $result = $this->validator->validate($original, $translation, 'ko');

        $this->assertTrue($result['valid']);
        $this->assertSame(0.0, $result['quality']['ratios']['unexpected_script']);
    }

    public function testGreekInGermanFails(): void
    {
        $original = $this->write('original', $this->englishCues());
        $translation = $this->write('greekmix', $this->cuesWithLines([
            ['Dies ist ein deutscher Satz, alles gut.'],
            ['Καλημέρα κόσμε, das sollte nicht passieren.'],
            ['Der letzte Satz der Aufnahme.'],
        ]));

        $result = $this->validator->validate($original, $translation, 'de');

        $this->assertFalse($result['valid']);
        $defects = array_filter($result['defects'], fn ($d) => $d['type'] === 'unexpected_script');
        $this->assertNotEmpty($defects);
        $this->assertArrayHasKey('greek', reset($defects)['scripts']);
    }

    public function testDiacriticsDoNotTriggerFalsePositives(): void
    {
        $original = $this->write('original', $this->englishCues());
        $translation = $this->write('diacritics', $this->cuesWithLines([
            ['Toto je česká věta s diakritikou, řádně.'],
            ['To jest polskie zdanie z ąćęłńóśźż, dobrze.'],
            ['Esta é uma frase portuguesa com acentos, ção.'],
        ]));

        // Czech diacritics under a Czech expectation
        $result = $this->validator->validate($original, $translation, 'cs');
        $this->assertTrue($result['valid']);
        $this->assertSame(0.0, $result['quality']['ratios']['unexpected_script']);

        // Portuguese diacritics under a Portuguese expectation
        $result = $this->validator->validate($original, $translation, 'pt');
        $this->assertTrue($result['valid'], 'Portuguese diacritics are Latin and must never flag');

        // The Polish letters are Latin too: even judged as Portuguese they
        // belong to the allowed script.
        $this->assertSame(0.0, $result['quality']['ratios']['unexpected_script']);
    }

    public function testUnknownLanguageSkipsTheCheck(): void
    {
        $original = $this->write('original', $this->englishCues());
        $translation = $this->write('scriptmix', $this->cuesWithLines([
            ['Ez egy magyar mondat, minden rendben.'],
            ['Mindenki a helyére ül vissza. Привет мир'],
            ['Ez az utolsó mondat a felvételen.'],
        ]));

        $result = $this->validator->validate($original, $translation, 'xx');

        $this->assertTrue($result['valid'], 'An unmapped language must skip the script check instead of guessing');
        $this->assertCount(0, array_filter($result['defects'], fn ($d) => $d['type'] === 'unexpected_script'));
    }

    public function testRegionalLanguageTagIsNormalized(): void
    {
        $original = $this->write('original', $this->englishCues());
        $translation = $this->write('chinese', $this->cuesWithLines([
            ['这是一句中文的台词，一切正常。'],
            ['所有人都回到了自己的座位上。'],
            ['这是录音里的最后一句话。'],
        ]));

        $result = $this->validator->validate($original, $translation, 'zh-CN');

        $this->assertTrue($result['valid'], 'zh-CN must be treated as zh for script purposes');
        $this->assertSame(0.0, $result['quality']['ratios']['unexpected_script']);
    }

    public function testSourceInheritedForeignCharsAreExempt(): void
    {
        // Real-world case (corpus job 44916): the English source already
        // spells a name with Greek homoglyph letters, and the translation
        // faithfully preserves it. The model did not hallucinate anything,
        // so this must NOT fail.
        $original = $this->write('original-greek', $this->cuesWithLines([
            ['I have four of my own ΜΙΑ,'],
            ['north of Durango.'],
            ['This is the last line.'],
        ]));
        $translation = $this->write('preserved', $this->cuesWithLines([
            ['Am patru dintre ai mei, ΜΙΑ,'],
            ['la nord de Durango.'],
            ['Aceasta este ultima linie.'],
        ]));

        $result = $this->validator->validate($original, $translation, 'ro');

        $this->assertTrue($result['valid'], 'Characters preserved from the source must not count as hallucinated');
        $this->assertSame(0.0, $result['quality']['ratios']['unexpected_script']);
        $this->assertCount(0, array_filter($result['defects'], fn ($d) => $d['type'] === 'unexpected_script'));
    }

    public function testIntroducedForeignCharsStillFailWithForeignSource(): void
    {
        // Same Greek source, but the translation hallucinates Cyrillic that
        // appears nowhere in the source: that must fail.
        $original = $this->write('original-greek', $this->cuesWithLines([
            ['I have four of my own ΜΙΑ,'],
            ['north of Durango.'],
            ['This is the last line.'],
        ]));
        $translation = $this->write('hallucinated', $this->cuesWithLines([
            ['Am patru dintre ai mei, ΜΙΑ, Привет'],
            ['la nord de Durango.'],
            ['Aceasta este ultima linie.'],
        ]));

        $result = $this->validator->validate($original, $translation, 'ro');

        $this->assertFalse($result['valid']);
        $defects = array_filter($result['defects'], fn ($d) => $d['type'] === 'unexpected_script');
        $this->assertNotEmpty($defects);
        $this->assertArrayHasKey('cyrillic', reset($defects)['scripts']);
        $this->assertArrayNotHasKey('greek', reset($defects)['scripts'], 'inherited Greek letters stay exempt');
    }

    public function testRelaxedScriptRatioKeepsVerdictUsable(): void
    {
        $original = $this->write('original', $this->englishCues());
        $translation = $this->write('scriptmix', $this->cuesWithLines([
            ['Ez egy magyar mondat, minden rendben. Őrá várunk, ő lesz az.'],
            ['Mindenki a helyére ül vissza, ő is jön.'],
            ['Ez az utolsó mondat a felvételen, ő mondja.'],
            ['Még egy magyar mondat következik most.'],
            ['A felvétel véget ér, köszönjük a figyelmet.'],
        ]));
        // Cyrillic run is small relative to the Hungarian accented letters.

        $this->validator->setMaxScriptRatio(0.5);
        $result = $this->validator->validate($original, $translation, 'hu');

        $this->assertTrue($result['valid'], 'A relaxed threshold must keep a mostly-clean translation usable');
        $this->assertLessThan(0.5, $result['quality']['ratios']['unexpected_script']);
    }

    public function testReadabilityIsReportedWithoutDefects(): void
    {
        // Matching timings so no timestamp defects interfere: cue 1 shows a
        // 50-char and a 29-char line (80 joined chars) over 2s = 40 cps.
        $original = $this->write('original-r', [
            [1.0, 3.0, ['This is an English line, everything fine.']],
            [4.0, 8.0, ['Everybody gets back to their seat.']],
            [9.0, 13.0, ['This is the last line of the recording.']],
        ]);
        $translation = $this->write('readability', [
            [1.0, 3.0, [str_repeat('é', 50), str_repeat('a', 29)]],
            [4.0, 8.0, ['Rövid mondat.']],
            [9.0, 13.0, ['Még egy rövid mondat.']],
        ]);

        $result = $this->validator->validate($original, $translation, 'hu');

        // Readability is statistics only: no defect, no warning, valid verdict.
        $this->assertTrue($result['valid']);
        $this->assertSame(0, $result['warning_count']);
        $this->assertSame([], $result['defects']);

        $readability = $result['quality']['readability'];
        $this->assertSame(40.0, $readability['max_cps']);
        $this->assertSame(1, $readability['max_cps_caption']);
        $this->assertSame(50, $readability['max_cpl']);
        $this->assertSame(1, $readability['max_cpl_caption']);
        $this->assertGreaterThan(0.0, $readability['avg_cps']);
        $this->assertLessThan(40.0, $readability['avg_cps']);
    }

    /**
     * @param list<array{0: float, 1: float, 2: list<string>}> $cues [start, end, lines]
     */
    private function write(string $name, array $cues): string
    {
        $path = $this->tmp . '/' . $name . '.srt';
        $this->fixtures[] = $path;

        $out = '';
        foreach ($cues as $i => [$start, $end, $lines]) {
            $out .= ($i + 1) . "\n" . $this->tc($start) . ' --> ' . $this->tc($end)
                . "\n" . implode("\n", $lines) . "\n\n";
        }
        file_put_contents($path, $out);
        return $path;
    }

    private function cuesWithLines(array $linesGroups): array
    {
        $cues = [];
        foreach ($linesGroups as $i => $lines) {
            $cues[] = [1.0 + 2 * $i, 2.0 + 2 * $i, $lines];
        }
        return $cues;
    }

    private function englishCues(): array
    {
        return $this->cuesWithLines([
            ['This is an English line, everything fine.'],
            ['Everybody gets back to their seat.'],
            ['This is the last line of the recording.'],
        ]);
    }

    private function tc(float $seconds): string
    {
        $ms = (int)round(($seconds - (int)$seconds) * 1000);
        return sprintf('%02d:%02d:%02d,%03d', 0, intdiv((int)$seconds, 60), (int)$seconds % 60, $ms);
    }
}
