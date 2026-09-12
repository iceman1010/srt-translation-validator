<?php

use PHPUnit\Framework\TestCase;
use SrtValidator\ScriptChecker;
use SrtValidator\SrtTranslationValidator;

/**
 * Gate behaviour introduced for the field-report fixes: same-language
 * passthrough (--source-lang / script fallback), music cues as non-content,
 * and the advisory near-verbatim measurement. Self-contained synthetic
 * fixtures, so it runs in CI without the local example files.
 */
class ValidatorGatesTest extends TestCase
{
    private $tmp;
    private $validator;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/srt-validator-gates-' . uniqid();
        mkdir($this->tmp, 0700, true);
        $this->validator = new SrtTranslationValidator();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*.srt') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->tmp)) {
            rmdir($this->tmp);
        }
    }

    public function testBaseLanguageNormalization(): void
    {
        $this->assertSame('es', ScriptChecker::baseLanguage('es-mx'));
        $this->assertSame('es', ScriptChecker::baseLanguage('ES-MX'));
        $this->assertSame('pt', ScriptChecker::baseLanguage('pt_BR'));
        $this->assertSame('de', ScriptChecker::baseLanguage('de'));
        $this->assertSame('zh', ScriptChecker::baseLanguage('zh-tw'));
    }

    public function testBaseLanguageCanonicalizesCodeFamiliesViaLingua(): void
    {
        // ISO 639-2/3 and legacy codes resolve to their ISO 639-1 form.
        $this->assertSame('de', ScriptChecker::baseLanguage('deu'));
        $this->assertSame('de', ScriptChecker::baseLanguage('ger'));
        $this->assertSame('fr', ScriptChecker::baseLanguage('fra'));
        $this->assertSame('cs', ScriptChecker::baseLanguage('ces'));
        $this->assertSame('zh', ScriptChecker::baseLanguage('zho'));
        $this->assertSame('he', ScriptChecker::baseLanguage('iw'));
        $this->assertSame('sr', ScriptChecker::baseLanguage('sr-Latn'));
    }

    public function testBaseLanguageKeepsCodesWithoutIso639_1Distinct(): void
    {
        // Cebuano and Filipino have no ISO 639-1 code: they must stay
        // distinct so same-language comparisons can never match them.
        $ceb = ScriptChecker::baseLanguage('ceb');
        $fil = ScriptChecker::baseLanguage('fil');

        $this->assertNotSame($ceb, $fil);
        $this->assertNotSame('', $ceb);
        $this->assertNotSame('', $fil);
    }

    public function testBaseLanguageUnparseableInputFallsBackLowercased(): void
    {
        $this->assertSame('???', ScriptChecker::baseLanguage(' ??? '));
    }

    public function testScriptGateRunsForIso639ThreeLetterInput(): void
    {
        // "zho" previously missed the language map and silently skipped the
        // script gate; canonicalized it runs with the Chinese allow-list.
        $result = (new ScriptChecker())->check(
            [['lines' => ['你好世界']]],
            'zho'
        );

        $this->assertNotNull($result);
        $this->assertSame(0, $result['foreign_chars']);
    }

    public function testSameLanguagePassthroughByDeclaration(): void
    {
        // An unchanged English copy offered as an "en" translation: with the
        // source language declared equal to the target, the verbatim gate
        // must be skipped (job 45224 scenario).
        $source = $this->writeSrt('source.en.srt', [
            [0, 2, 'Hello there my friend'],
            [2, 4, 'How are you today'],
            [4, 6, 'I am fine thank you'],
        ]);
        $copy = $this->writeSrt('copy.en.srt', [
            [0, 2, 'Hello there my friend'],
            [2, 4, 'How are you today'],
            [4, 6, 'I am fine thank you'],
        ]);

        $this->validator->setSourceLanguage('en');
        $result = $this->validator->validate($source, $copy, 'en');

        $this->assertTrue($result['valid'], 'A same-language passthrough with a declared source must pass');
        $this->assertSame(0, $result['error_count']);
        $this->assertSame(1.0, $result['quality']['ratios']['verbatim_copy'], 'The copy is still measured as verbatim');
        $passthrough = array_filter($result['defects'], fn ($d) => $d['type'] === 'same_language_passthrough');
        $this->assertCount(1, $passthrough);
        $this->assertSame('warning', reset($passthrough)['severity']);
        $this->assertCount(
            0,
            array_filter($result['defects'], fn ($d) => $d['type'] === 'untranslated_copy'),
            'The verbatim gate must not fire for a passthrough'
        );

        // Without the declaration the same copy is an untranslated failure.
        $this->validator->setSourceLanguage(null);
        $result = $this->validator->validate($source, $copy, 'en');
        $this->assertFalse($result['valid']);
        $this->assertCount(
            1,
            array_filter($result['defects'], fn ($d) => $d['type'] === 'untranslated_copy'),
            'Without a declared source language an unchanged copy must fail'
        );
    }

    public function testSameLanguagePassthroughInferredFromSourceScript(): void
    {
        // Bulgarian source offered as a "bg" translation, nothing declared:
        // the Cyrillic source script itself proves the passthrough.
        $captions = [
            [0, 2, 'Здравей свят, как си днес'],
            [2, 4, 'Аз съм добре, благодаря'],
            [4, 6, 'Какво правиш сега'],
        ];
        $source = $this->writeSrt('source.srt', $captions);
        $copy = $this->writeSrt('copy.srt', $captions);

        $result = $this->validator->validate($source, $copy, 'bg');

        $this->assertTrue($result['valid'], 'A same-script passthrough must pass without --source-lang');
        $this->assertSame(0, $result['error_count']);
        $this->assertCount(1, array_filter($result['defects'], fn ($d) => $d['type'] === 'same_language_passthrough'));
    }

    public function testLatinSourceIntoNonLatinTargetStillFails(): void
    {
        // English source unchanged, but the target is Bulgarian: the script
        // fallback must NOT fire (the source has no Cyrillic), so the
        // verbatim copy is a genuine untranslated failure (job 44924 shape).
        $captions = [
            [0, 2, 'Hello there my friend'],
            [2, 4, 'How are you today'],
            [4, 6, 'I am fine thank you'],
        ];
        $source = $this->writeSrt('source.srt', $captions);
        $copy = $this->writeSrt('copy.srt', $captions);

        $result = $this->validator->validate($source, $copy, 'bg');

        $this->assertFalse($result['valid'], 'A Latin verbatim copy into a non-Latin target must fail');
        $this->assertCount(1, array_filter($result['defects'], fn ($d) => $d['type'] === 'untranslated_copy'));
        $this->assertCount(0, array_filter($result['defects'], fn ($d) => $d['type'] === 'same_language_passthrough'));
    }

    public function testDeclaredDifferentLanguageKeepsGateArmed(): void
    {
        // English source, Dutch target, English copy: declaring the source
        // language arms the gate instead of defusing it.
        $captions = [
            [0, 2, 'Hello there my friend'],
            [2, 4, 'How are you today'],
            [4, 6, 'I am fine thank you'],
        ];
        $source = $this->writeSrt('source.srt', $captions);
        $copy = $this->writeSrt('copy.srt', $captions);

        $this->validator->setSourceLanguage('en');
        $result = $this->validator->validate($source, $copy, 'nl');

        $this->assertFalse($result['valid']);
        $this->assertCount(1, array_filter($result['defects'], fn ($d) => $d['type'] === 'untranslated_copy'));
    }

    public function testMusicCuesAreNotContentLoss(): void
    {
        // Music-only cues dropped at the end of the file: not content loss,
        // not even a merge warning (job 44925 scenario).
        $source = $this->writeSrt('source.srt', [
            [0, 2, 'Hello there my friend'],
            [2, 4, 'How are you today'],
            [4, 6, '♪♪'],
            [6, 8, '♪ ♪ ♪'],
        ]);
        $translation = $this->writeSrt('translation.srt', [
            [0, 2, 'Hallo daar mijn vriend'],
            [2, 4, 'Hoe gaat het vandaag'],
        ]);

        $result = $this->validator->validate($source, $translation, 'nl');

        $this->assertTrue($result['valid'], 'Dropped music cues must not fail the job');
        $this->assertSame(0, $result['error_count']);
        $this->assertSame(0, $result['warning_count']);
        $this->assertSame(0.0, $result['quality']['ratios']['content_loss']);
        $this->assertCount(0, array_filter($result['defects'], fn ($d) => $d['type'] === 'missing_caption'));
        $this->assertCount(0, array_filter($result['defects'], fn ($d) => $d['type'] === 'merged_captions'));
    }

    public function testDroppedDialogueStillCountsAmongMusicCues(): void
    {
        // The same tail, but a dialogue caption is dropped together with the
        // music cues: only the dialogue caption may count as missing.
        $source = $this->writeSrt('source.srt', [
            [0, 2, 'Hello there my friend'],
            [2, 4, '♪♪'],
            [4, 6, 'Goodbye my dear friend'],
            [6, 8, '♪ ♪ ♪'],
        ]);
        $translation = $this->writeSrt('translation.srt', [
            [0, 2, 'Hallo daar mijn vriend'],
        ]);

        $result = $this->validator->validate($source, $translation, 'nl');

        $missing = array_filter($result['defects'], fn ($d) => $d['type'] === 'missing_caption');
        $this->assertCount(1, $missing, 'Only the dialogue caption is content');
        $this->assertSame(0.5, $result['quality']['ratios']['content_loss']);
        $this->assertFalse($result['valid']);
    }

    public function testEditedCopyFailsDistinctFromVerbatimCopy(): void
    {
        // A lightly edited copy: not exactly verbatim, but ~98% similar.
        // It must fail as an edited_copy - a different failure mode than a
        // pure untranslated_copy (model tweaked the source vs. returned it).
        $source = $this->writeSrt('source.srt', [
            [0, 2, 'The quick brown fox jumps over the lazy dog near the river bank'],
        ]);
        $edited = $this->writeSrt('edited.srt', [
            [0, 2, 'The quick brown fox jumps over the lazy dog near the river banks'],
        ]);
        $exact = $this->writeSrt('exact.srt', [
            [0, 2, 'The quick brown fox jumps over the lazy dog near the river bank'],
        ]);

        $result = $this->validator->validate($source, $edited, 'nl');
        $this->assertFalse($result['valid'], 'A lightly edited copy must fail the near-verbatim gate');
        $this->assertCount(1, array_filter($result['defects'], fn ($d) => $d['type'] === 'edited_copy'));
        $this->assertCount(0, array_filter($result['defects'], fn ($d) => $d['type'] === 'untranslated_copy'));
        $this->assertSame(0.0, $result['quality']['ratios']['verbatim_copy'], 'An edited pair is not exactly verbatim');
        $this->assertGreaterThan(0.9, $result['quality']['ratios']['near_verbatim_copy']);
        $this->assertSame(0.5, $result['quality']['thresholds']['near_verbatim_copy']);

        // The pure copy fails as untranslated_copy, never as edited_copy.
        $result = $this->validator->validate($source, $exact, 'nl');
        $this->assertFalse($result['valid']);
        $this->assertCount(1, array_filter($result['defects'], fn ($d) => $d['type'] === 'untranslated_copy'));
        $this->assertCount(0, array_filter($result['defects'], fn ($d) => $d['type'] === 'edited_copy'));

        // The threshold is overridable: with the near-verbatim limit at 100%
        // the edited pair stays usable.
        $this->validator->setMaxNearVerbatimRatio(1.0);
        $result = $this->validator->validate($source, $edited, 'nl');
        $this->assertTrue($result['valid'], 'A raised near-verbatim limit must let the edited pair pass');
    }

    /**
     * @param list<array{0: float, 1: float, 2: string}> $captions
     */
    private function writeSrt(string $name, array $captions): string
    {
        $path = $this->tmp . '/' . $name;
        $blocks = [];
        foreach ($captions as $i => [$start, $end, $text]) {
            $blocks[] = ($i + 1) . "\n"
                . sprintf('%s --> %s', $this->tc($start), $this->tc($end))
                . "\n" . $text . "\n";
        }
        file_put_contents($path, implode("\n", $blocks));
        return $path;
    }

    private function tc(float $seconds): string
    {
        $ms = (int)round(($seconds - (int)$seconds) * 1000);
        return sprintf('%02d:%02d:%02d,%03d', 0, intdiv((int)$seconds, 60), (int)$seconds % 60, $ms);
    }

    /**
     * A 12-cue English source plus a translation that merges cues #4 and
     * #9-#10 into their neighbours (25% merged). $text factory receives the
     * 0-based cue index and returns that cue's translated text.
     *
     * @param callable(int): string $text
     * @return array{0: string, 1: string} source path, translation path
     */
    private function mergeFixture(callable $text, string $translationName): array
    {
        $source = $this->writeSrt('source.en.srt', array_map(
            fn (int $i): array => [$i * 2, $i * 2 + 2, "English dialogue line number " . ($i + 1) . " with some words"],
            range(0, 11)
        ));

        // Drop cues #4 (index 3) and #9-#10 (indices 8, 9): the remaining
        // nine translation cues anchor exactly, the holes are merges.
        $kept = array_filter(range(0, 11), fn (int $i): bool => !in_array($i, [3, 8, 9], true));
        $translation = $this->writeSrt($translationName, array_map(
            fn (int $i): array => [$i * 2, $i * 2 + 2, $text($i)],
            array_values($kept)
        ));

        return [$source, $translation];
    }

    public function testMergedCaptionMessagesUseSingularAndPluralGrammar(): void
    {
        [$source, $translation] = $this->mergeFixture(
            fn (int $i): string => '日本語のセリフ' . ($i + 1),
            'translation.ja.srt'
        );
        $this->validator->setSourceLanguage('en');

        $result = $this->validator->validate($source, $translation, 'ja');

        $messages = array_map(
            fn (array $d): string => $d['message'],
            array_filter($result['defects'], fn (array $d): bool => $d['type'] === 'merged_captions')
        );
        $this->assertSame([
            'Original caption #4 is merged into translation caption #4',
            'Original captions #9-10 are merged into translation caption #8',
        ], $messages);
    }

    public function testDenseTargetLanguageGetsAdaptiveMergeTolerance(): void
    {
        // 25% merged: over the 10% base tolerance, under the en->ja
        // adaptive tolerance (10% x 42/13 cpl = ~32%).
        [$source, $translation] = $this->mergeFixture(
            fn (int $i): string => '日本語のセリフ' . ($i + 1),
            'translation.ja.srt'
        );
        $this->validator->setSourceLanguage('en');

        $result = $this->validator->validate($source, $translation, 'ja');

        $this->assertTrue($result['valid']);
        $this->assertSame(0.25, $result['quality']['ratios']['merged']);
        $this->assertEqualsWithDelta(0.3231, $result['quality']['thresholds']['merged'], 0.001);
    }

    public function testAdaptiveToleranceUsesDetectedSourceLanguage(): void
    {
        // No --source-lang: the source language is detected from the file.
        [$source, $translation] = $this->mergeFixture(
            fn (int $i): string => '日本語のセリフ' . ($i + 1),
            'translation.ja.srt'
        );

        $result = $this->validator->validate($source, $translation, 'ja');

        $this->assertTrue($result['valid']);
    }

    public function testSameDensityPairsKeepBaseMergeTolerance(): void
    {
        // en -> de: both default profile (42 cpl), factor 1, threshold 10%.
        [$source, $translation] = $this->mergeFixture(
            fn (int $i): string => 'Deutsche Dialogzeile Nummer ' . ($i + 1) . ' mit einigen Wörtern',
            'translation.de.srt'
        );
        $this->validator->setSourceLanguage('en');

        $result = $this->validator->validate($source, $translation, 'de');

        $this->assertFalse($result['valid']);
        $this->assertContains('merged 25.00% exceeds the threshold 10.00%', $result['quality']['reasons']);
    }

    public function testVerboseTargetKeepsBaseMergeTolerance(): void
    {
        // ja -> en: the factor clamps at 1 (verbose targets merge less, not more).
        // Japanese source, English translation missing the same cues.
        $jaSource = $this->writeSrt('source.ja.srt', array_map(
            fn (int $i): array => [$i * 2, $i * 2 + 2, '日本語のセリフ' . ($i + 1)],
            range(0, 11)
        ));
        $kept = array_filter(range(0, 11), fn (int $i): bool => !in_array($i, [3, 8, 9], true));
        $enTranslation = $this->writeSrt('translation.en.srt', array_map(
            fn (int $i): array => [$i * 2, $i * 2 + 2, "English dialogue line number " . ($i + 1) . " with some words"],
            array_values($kept)
        ));
        $this->validator->setSourceLanguage('ja');

        $result = $this->validator->validate($jaSource, $enTranslation, 'en');

        $this->assertFalse($result['valid']);
        $this->assertContains('merged 25.00% exceeds the threshold 10.00%', $result['quality']['reasons']);
    }

    public function testExplicitMergeRatioOverridesAdaptiveTolerance(): void
    {
        [$source, $translation] = $this->mergeFixture(
            fn (int $i): string => '日本語のセリフ' . ($i + 1),
            'translation.ja.srt'
        );
        $this->validator->setSourceLanguage('en');
        $this->validator->setMaxMergeRatio(0.05);

        $result = $this->validator->validate($source, $translation, 'ja');

        $this->assertFalse($result['valid']);
        $this->assertSame(0.05, $result['quality']['thresholds']['merged']);
        $this->assertContains('merged 25.00% exceeds the threshold 5.00%', $result['quality']['reasons']);
    }
}
