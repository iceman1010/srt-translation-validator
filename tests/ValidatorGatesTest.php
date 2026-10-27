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
        // The fixture is deliberately LARGE (> 5000 chars of copy text): a
        // corpus that small-sample mode could not language-diagnose would
        // demote the near-verbatim gate instead (see the small-corpus tests).
        $cues = [];
        $edited = [];
        for ($i = 0; $i < 90; $i++) {
            $start = $i * 3.0;
            $cues[] = [$start, $start + 2.8, "English dialogue sentence number $i which carries enough words to fill the copy corpus"];
            $edited[] = [$start, $start + 2.8, "English dialogue sentence number $i which holds enough words to fill the copy corpus"];
        }
        $source = $this->writeSrt('source.srt', $cues);
        $editedFile = $this->writeSrt('edited.srt', $edited);
        $exact = $this->writeSrt('exact.srt', $cues);

        $result = $this->validator->validate($source, $editedFile, 'nl');
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
        $result = $this->validator->validate($source, $editedFile, 'nl');
        $this->assertTrue($result['valid'], 'A raised near-verbatim limit must let the edited pair pass');
    }

    public function testSmallCopyCorpusPartialCopyStaysWarning(): void
    {
        // Production error #29 pattern: a 4-cue YTS watermark file (nl->en).
        // 3 cues are brand boilerplate that reads the same in both
        // languages, one cue is genuinely paraphrased (Find -> Visit). The
        // copy corpus is ~100 bytes, far below the 5000-char language-
        // detection floor: indeterminate. A majority of copies proves
        // nothing there, so the finding demotes to a warning and the file
        // stays usable.
        $source = $this->writeSrt('yts-source.srt', [
            [30.0, 36.0, 'Provided by YTS.LT'],
            [36.5, 42.0, 'Find the official YIFY movies site at https://YTS.LT'],
            [1830.0, 1836.0, 'Downloaded from YTS.LT'],
            [3630.0, 3636.0, 'Download more movies for free from YTS.LT'],
        ]);
        $translation = $this->writeSrt('yts-translation.srt', [
            [30.0, 36.0, 'Provided by YTS.LT'],
            [36.5, 42.0, 'Visit the official YIFY movies site at https://YTS.LT'],
            [1830.0, 1836.0, 'Downloaded from YTS.LT'],
            [3630.0, 3636.0, 'Download more movies for free from YTS.LT'],
        ]);

        $result = $this->validator->validate($source, $translation, 'en');

        $this->assertTrue($result['valid'], 'A tiny boilerplate file must not fail as an untranslated copy');
        $copies = array_values(array_filter($result['defects'], fn ($d) => $d['type'] === 'untranslated_copy'));
        $this->assertCount(1, $copies, 'The copy observation stays visible');
        $this->assertSame('warning', $copies[0]['severity']);
        $this->assertStringContainsString('too small', $copies[0]['message']);
        $this->assertSame(0.75, $result['quality']['ratios']['verbatim_copy']);
        $this->assertSame(0.9, $result['quality']['thresholds']['verbatim_copy']);
        $this->assertNull($result['quality']['thresholds']['near_verbatim_copy']);
    }

    public function testSmallCopyCorpusFullCopyStillFails(): void
    {
        // Same tiny size, but every cue came back word-for-word: that is a
        // genuine small passthrough, and the raised bar (>= 90% verbatim)
        // still fails it.
        $captions = [
            [0.0, 2.0, 'Hello there my friend'],
            [2.0, 4.0, 'How are you today'],
            [4.0, 6.0, 'I am fine thank you'],
            [6.0, 8.0, 'And how are you doing'],
        ];
        $source = $this->writeSrt('small-source.srt', $captions);
        $copy = $this->writeSrt('small-copy.srt', $captions);

        $result = $this->validator->validate($source, $copy, 'nl');

        $this->assertFalse($result['valid'], 'A full verbatim copy fails even on the raised small-sample bar');
        $copies = array_values(array_filter($result['defects'], fn ($d) => $d['type'] === 'untranslated_copy'));
        $this->assertCount(1, $copies);
        $this->assertSame('error', $copies[0]['severity']);
        $this->assertSame(0.9, $result['quality']['thresholds']['verbatim_copy']);
    }

    public function testSmallEditedCopyStaysWarning(): void
    {
        // Three lightly edited cues (0% verbatim, 100% near-verbatim): the
        // similarity score is dominated by shared boilerplate on short
        // cues, so in small-sample mode near-verbatim can never fail the
        // file - the finding demotes to a warning.
        $cues = [];
        $edited = [];
        for ($i = 0; $i < 3; $i++) {
            $start = $i * 3.0;
            $cues[] = [$start, $start + 2.8, "The quick brown fox number $i jumps over the lazy dog near the river bank"];
            $edited[] = [$start, $start + 2.8, "The quick brown fox number $i jumps over the lazy dog near the river banks"];
        }
        $source = $this->writeSrt('small-edited-source.srt', $cues);
        $editedFile = $this->writeSrt('small-edited.srt', $edited);

        $result = $this->validator->validate($source, $editedFile, 'nl');

        $this->assertTrue($result['valid'], 'Near-verbatim similarity cannot fail a small-sample file');
        $copies = array_values(array_filter($result['defects'], fn ($d) => $d['type'] === 'edited_copy'));
        $this->assertCount(1, $copies, 'The edited-copy observation stays visible');
        $this->assertSame('warning', $copies[0]['severity']);
        $this->assertNull($result['quality']['thresholds']['near_verbatim_copy']);
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

    public function testMergesAreAdvisoryForDenseTargets(): void
    {
        // 25% merged into a dense target (en -> ja): merging is
        // re-segmentation style, never a fault on its own - reported as a
        // ratio, but the verdict gate stays off unless explicitly set.
        [$source, $translation] = $this->mergeFixture(
            fn (int $i): string => '日本語のセリフ' . ($i + 1),
            'translation.ja.srt'
        );
        $this->validator->setSourceLanguage('en');

        $result = $this->validator->validate($source, $translation, 'ja');

        $this->assertTrue($result['valid']);
        $this->assertSame(0.25, $result['quality']['ratios']['merged']);
        $this->assertNull($result['quality']['thresholds']['merged']);
    }

    public function testMergesAreAdvisoryWithoutDeclaredSourceLanguage(): void
    {
        // No --source-lang: detection is irrelevant to the merge verdict -
        // merging is advisory for every language pair.
        [$source, $translation] = $this->mergeFixture(
            fn (int $i): string => '日本語のセリフ' . ($i + 1),
            'translation.ja.srt'
        );

        $result = $this->validator->validate($source, $translation, 'ja');

        $this->assertTrue($result['valid']);
        $this->assertNull($result['quality']['thresholds']['merged']);
    }

    public function testMergesAreAdvisoryForSameDensityPairs(): void
    {
        // en -> de: both default profile (42 cpl). 25% merged stays
        // advisory - real content loss is the content_loss gate's job.
        [$source, $translation] = $this->mergeFixture(
            fn (int $i): string => 'Deutsche Dialogzeile Nummer ' . ($i + 1) . ' mit einigen Wörtern',
            'translation.de.srt'
        );
        $this->validator->setSourceLanguage('en');

        $result = $this->validator->validate($source, $translation, 'de');

        $this->assertTrue($result['valid']);
        $this->assertSame(0.25, $result['quality']['ratios']['merged']);
        $this->assertNull($result['quality']['thresholds']['merged']);
    }

    public function testVerboseTargetMergingIsAdvisory(): void
    {
        // ja -> en, production error #6 pattern (2026-09-21): short Japanese
        // fragments legitimately merge into full English lines (~13% on real
        // jobs). 25% merged must stay advisory - the translation did nothing
        // wrong, and real loss is measured by content_loss.
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

        $this->assertTrue($result['valid']);
        $this->assertSame(0.25, $result['quality']['ratios']['merged']);
        $this->assertNull($result['quality']['thresholds']['merged']);
    }

    public function testMixedLanguageSourceExcludesLegitimateCopies(): void
    {
        // Production error #20 pattern: the "English" source contains a
        // large block already written in the target language. A correct
        // translation leaves that block unchanged - those pairs are exempt
        // (the copy corpus detects nl and real translation work exists in
        // the English cues), so the file must NOT fail as a passthrough.
        $cues = [];
        $translation = [];
        for ($i = 0; $i < 40; $i++) {
            $start = $i * 6.0;
            $cues[] = [$start, $start + 5.5, "English dialogue line number $i with several fairly common words in it"];
            $translation[] = [$start, $start + 5.5, "Nederlandse dialoog regel nummer $i met verschillende veelvoorkomende woorden erin"];
        }
        for ($i = 40; $i < 100; $i++) {
            $start = $i * 6.0;
            $cues[] = [$start, $start + 5.5, "Dit is een nederlandse bronregel met nummer $i die al volledig in de doeltaal is geschreven"];
            $translation[] = [$start, $start + 5.5, "Dit is een nederlandse bronregel met nummer $i die al volledig in de doeltaal is geschreven"];
        }
        $source = $this->writeSrt('mixed-source.srt', $cues);
        $translationFile = $this->writeSrt('mixed-translation.srt', $translation);

        $result = $this->validator->validate($source, $translationFile, 'nl');

        $this->assertTrue($result['valid'], 'Legitimately unchanged target-language cues must not fail the file');
        $this->assertNotEmpty(
            array_filter($result['defects'], fn (array $d): bool => $d['type'] === 'mixed_language_source'),
            'The mixed-source correction must stay visible'
        );
    }

    public function testSourceAlreadyInTargetLanguageStillFails(): void
    {
        // Production error #23 pattern: a Finnish file submitted into an
        // en->fi job - the "translation" is a copy of a source that is
        // already in the target language, with NO real translation work
        // anywhere. The mixed-source exemption must not apply, and the
        // language mismatch gets its own warning.
        $cues = [];
        for ($i = 0; $i < 60; $i++) {
            $start = $i * 6.0;
            $cues[] = [$start, $start + 5.5, "Tämä on suomenkielinen lause numero $i, jossa on riittävästi sanoja mittaamista varten."];
        }
        $source = $this->writeSrt('fi-source.srt', $cues);
        $translation = $this->writeSrt('fi-translation.srt', $cues);

        $result = $this->validator->validate($source, $translation, 'fi');

        $this->assertFalse($result['valid'], 'A file that is already in the target language did no translation work');
        $this->assertNotEmpty(
            array_filter($result['defects'], fn (array $d): bool => $d['type'] === 'source_already_in_target_language'),
            'The language mismatch must be reported explicitly'
        );
    }

    public function testExplicitMergeRatioReactivatesTheGate(): void
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

    /**
     * Error #4 class (2026-10-27): a source cue whose text is split by a
     * whitespace-only line, orphaning the sentence continuation where a
     * timestamp belongs. The pipeline leaks the fragment verbatim into the
     * translation, where the format gate orphans it again.
     */
    private function writeInheritedCorruptionPair(string $orphanInTranslation): array
    {
        $source = $this->tmp . '/inherited-source.it.srt';
        file_put_contents($source, implode("\n", [
            '1',
            '00:00:01,000 --> 00:00:03,000',
            'prima riga del source',
            '',
            '2',
            '00:00:03,100 --> 00:00:05,000',
            'seconda riga del source',
            ' ',
            'the orphan continuation text',
            '',
            '3',
            '00:00:05,100 --> 00:00:07,000',
            'terza riga del source',
            '',
            '',
        ]));

        $translation = $this->tmp . '/inherited-target.ja.srt';
        file_put_contents($translation, implode("\n", [
            '1',
            '00:00:01,000 --> 00:00:03,000',
            '最初の行です',
            '',
            '2',
            '00:00:03,100 --> 00:00:05,000',
            '二番目の行です',
            ' ',
            $orphanInTranslation,
            '',
            '3',
            '00:00:05,100 --> 00:00:07,000',
            '三番目の行です',
            '',
            '',
        ]));

        return [$source, $translation];
    }

    public function testSourceInheritedCorruptionIsAttributedNotModelBlamed(): void
    {
        [$source, $translation] = $this->writeInheritedCorruptionPair('the orphan continuation text');

        $result = $this->validator->validate($source, $translation, 'ja');

        $this->assertFalse($result['valid'], 'The translation is undeliverable regardless of attribution');

        $types = array_column($result['defects'], 'type');
        $this->assertContains('invalid_format', $types, 'The format error still stands');
        $this->assertContains('source_corruption_inherited', $types, 'The inherited corruption is attributed');

        $inherited = array_values(array_filter(
            $result['defects'],
            fn (array $d): bool => $d['type'] === 'source_corruption_inherited'
        ))[0];
        $this->assertSame('warning', $inherited['severity'], 'Attribution must not fail the model twice');
        $this->assertSame([9], $inherited['source_lines'], 'The orphan line sits at source line 9');
        $this->assertNotEmpty($inherited['translation_lines']);

        $this->assertTrue($this->validator->hasDefect('source_corruption_inherited'));
    }

    public function testCleanSourceKeepsFormatFailureModelSide(): void
    {
        // Error #28 shape: the orphan fragment is target-language text with
        // no source counterpart - a translation-side failure.
        [$source, $translation] = $this->writeInheritedCorruptionPair('ここは翻訳側の抜け行です');

        // Replace the source with a clean version of the same cues.
        $cleanSource = $this->tmp . '/clean-source.it.srt';
        file_put_contents($cleanSource, implode("\n", [
            '1',
            '00:00:01,000 --> 00:00:03,000',
            'prima riga del source',
            '',
            '2',
            '00:00:03,100 --> 00:00:05,000',
            'seconda riga del source',
            '',
            '3',
            '00:00:05,100 --> 00:00:07,000',
            'terza riga del source',
            '',
            '',
        ]));

        $result = $this->validator->validate($cleanSource, $translation, 'ja');

        $this->assertFalse($result['valid']);
        $types = array_column($result['defects'], 'type');
        $this->assertContains('invalid_format', $types);
        $this->assertNotContains('source_corruption_inherited', $types, 'No source counterpart - no inheritance');
        $this->assertFalse($this->validator->hasDefect('source_corruption_inherited'));
    }

    public function testCorruptSourceWithoutVerbatimMatchStaysModelSide(): void
    {
        // Both files format-invalid, but the orphan lines differ: "both
        // invalid" alone must not attribute the corruption to the source.
        [$source, $translation] = $this->writeInheritedCorruptionPair('ein ganz anderer satz');

        $result = $this->validator->validate($source, $translation, 'ja');

        $this->assertFalse($result['valid']);
        $types = array_column($result['defects'], 'type');
        $this->assertContains('invalid_format', $types);
        $this->assertNotContains('source_corruption_inherited', $types, 'Only a verbatim match proves inheritance');
    }
}
