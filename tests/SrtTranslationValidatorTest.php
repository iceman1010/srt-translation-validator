<?php

use PHPUnit\Framework\TestCase;
use SrtValidator\SrtTranslationValidator;

class SrtTranslationValidatorTest extends TestCase
{
    private $validator;
    private $examplesDir;

    protected function setUp(): void
    {
        $this->validator = new SrtTranslationValidator();
        $this->examplesDir = __DIR__ . '/../examples/';

        // The Matrix example files are local-only fixtures (see .gitignore);
        // these integration tests are skipped when they are not checked out.
        if (!is_file($this->examplesDir . 'The.Matrix.1999.Tubi.CC.en.srt')
            || !is_file($this->examplesDir . 'The.Matrix.1999.Tubi.CC.de.srt')) {
            $this->markTestSkipped('Local example fixtures are not present');
        }
    }

    public function testValidTranslation()
    {
        $originalPath = $this->examplesDir . 'The.Matrix.1999.Tubi.CC.en.srt';
        $translationPath = $this->examplesDir . 'The.Matrix.1999.Tubi.CC.de.srt';

        $result = $this->validator->validate($originalPath, $translationPath, 'de');

        $this->assertTrue($result['valid'], 'Valid translation should pass validation');
        $this->assertEmpty($result['defects'], 'Valid translation should have no defects');
        $this->assertSame(0, $result['error_count']);
        $this->assertSame(0, $result['warning_count']);

        foreach ($result['quality']['ratios'] as $name => $ratio) {
            if ($name === 'verbatim_copy' || $name === 'near_verbatim_copy') {
                // Names and short interjections legitimately stay identical
                // (or near-identical) across languages; only a near-total
                // copy may fail.
                $this->assertLessThan(0.2, $ratio, "Ratio {$name} of a real translation must stay small");
                continue;
            }
            $this->assertSame(0.0, $ratio, "Ratio {$name} should be zero for a perfect translation");
        }
    }

    public function testMissingCaptionsDefect()
    {
        $originalPath = $this->examplesDir . 'The.Matrix.1999.Tubi.CC.en.srt';
        $translationPath = $this->examplesDir . 'defect_missing_parts.de.srt';

        $result = $this->validator->validate($originalPath, $translationPath, 'de');

        $this->assertFalse($result['valid'], '25 missing captions (1.49% of content captions) should fail validation');

        $missing = array_filter($result['defects'], function ($defect) {
            return $defect['type'] === 'missing_caption';
        });

        $this->assertCount(25, $missing);
        $this->assertSame(25, $result['error_count']);
        // The loss ratio is measured over CONTENT captions only (music cues
        // and annotations are excluded from the denominator).
        $this->assertEqualsWithDelta(0.0149, $result['quality']['ratios']['content_loss'], 0.0005);

        // The removed captions sit in the middle of the file, so nothing
        // may be reported as merged.
        $this->assertCount(0, array_filter($result['defects'], fn ($d) => $d['type'] === 'merged_captions'));
    }

    public function testTimestampDriftIsUsableWithinThreshold()
    {
        // 20 shifted captions out of 1834 (~1% drift) stay within the 2%
        // threshold: the translation is reported as usable, with the
        // individual drift defects still listed for inspection.
        $originalPath = $this->examplesDir . 'The.Matrix.1999.Tubi.CC.en.srt';
        $translationPath = $this->examplesDir . 'defect_timestamp_mismatch.de.srt';

        $result = $this->validator->validate($originalPath, $translationPath, 'de');

        $this->assertTrue($result['valid'], 'Drift within the threshold should stay usable');
        $this->assertGreaterThanOrEqual(15, $result['error_count']);

        $drifts = array_filter($result['defects'], fn ($d) => $d['type'] === 'timestamp_mismatch');
        $this->assertNotEmpty($drifts);
        $this->assertLessThanOrEqual(25, count($drifts));

        foreach ($drifts as $defect) {
            $this->assertSame('error', $defect['severity']);
            // The shift was applied around captions 301-320; the alignment
            // may displace the attribution by a cue or two.
            $this->assertGreaterThanOrEqual(295, $defect['caption_number']);
            $this->assertLessThanOrEqual(325, $defect['caption_number']);
        }
    }

    public function testTimestampDriftFailsInStrictMode()
    {
        $originalPath = $this->examplesDir . 'The.Matrix.1999.Tubi.CC.en.srt';
        $translationPath = $this->examplesDir . 'defect_timestamp_mismatch.de.srt';

        $this->validator->setStrict(true);
        $result = $this->validator->validate($originalPath, $translationPath, 'de');

        $this->assertFalse($result['valid'], 'Strict mode must fail on any error-severity defect');
        $this->assertStringContainsString('strict mode', implode(' ', $result['quality']['reasons']));
    }

    public function testTimestampDriftFailsBelowOverrideThreshold()
    {
        $originalPath = $this->examplesDir . 'The.Matrix.1999.Tubi.CC.en.srt';
        $translationPath = $this->examplesDir . 'defect_timestamp_mismatch.de.srt';

        $this->validator->setMaxDriftRatio(0.005);
        $result = $this->validator->validate($originalPath, $translationPath, 'de');

        $this->assertFalse($result['valid'], 'A stricter threshold must flip the verdict');
        $this->assertStringContainsString('timestamp drift', implode(' ', $result['quality']['reasons']));
    }

    public function testThresholdOverrideCanFlipVerdictToUsable()
    {
        $originalPath = $this->examplesDir . 'The.Matrix.1999.Tubi.CC.en.srt';
        $translationPath = $this->examplesDir . 'defect_missing_parts.de.srt';

        $this->validator->setMaxLossRatio(0.02);
        $result = $this->validator->validate($originalPath, $translationPath, 'de');

        $this->assertTrue($result['valid'], '1.36% loss is tolerable with a 2% threshold');
        $this->assertSame(25, $result['error_count'], 'The defects are still reported');
    }

    public function testMergedCaptionsAreWarnings()
    {
        // Simulate DeepL-style re-segmentation: remove single captions from
        // the middle of an otherwise perfect translation. The surrounding
        // cues still anchor exactly, so the holes are merges, not loss.
        $original = Done\Subtitles\Subtitles::loadFromFile(
            $this->examplesDir . 'The.Matrix.1999.Tubi.CC.de.srt'
        );
        $blocks = $original->getInternalFormat();
        unset($blocks[600], $blocks[1000]);
        $original->setInternalFormat(array_values($blocks));
        $mergedPath = $this->examplesDir . 'tmp_merged.de.srt';
        $original->save($mergedPath);

        try {
            $result = $this->validator->validate(
                $this->examplesDir . 'The.Matrix.1999.Tubi.CC.en.srt',
                $mergedPath,
                'de'
            );

            $this->assertTrue($result['valid'], 'Harmless re-segmentation must stay usable');
            $this->assertSame(0, $result['error_count']);
            $this->assertSame(2, $result['warning_count']);

            $merges = array_filter($result['defects'], fn ($d) => $d['type'] === 'merged_captions');
            $this->assertCount(2, $merges);
            foreach ($merges as $defect) {
                $this->assertSame('warning', $defect['severity']);
            }
        } finally {
            @unlink($mergedPath);
        }
    }

    public function testTimestampDetectionPrecision()
    {
        $this->validator->setTimestampTolerance(0.1); // 100ms tolerance

        $originalPath = $this->examplesDir . 'The.Matrix.1999.Tubi.CC.en.srt';
        $translationPath = $this->examplesDir . 'The.Matrix.1999.Tubi.CC.de.srt';

        $result = $this->validator->validate($originalPath, $translationPath, 'de');

        $this->assertTrue($result['valid'], 'Valid translation should still be valid with strict tolerance');
        $this->assertEmpty($result['defects'], 'No false positives even with strict tolerance');
    }

    public function testPartialTranslationDefect()
    {
        $originalPath = $this->examplesDir . 'The.Matrix.1999.Tubi.CC.en.srt';
        $translationPath = $this->examplesDir . 'defect_partial_translation.de.srt';

        $result = $this->validator->validate($originalPath, $translationPath, 'de');

        $this->assertFalse($result['valid'], 'Half the file as verbatim English must fail validation (untranslated copy)');

        $partialTranslationDefects = array_filter($result['defects'], function ($defect) {
            return $defect['type'] === 'partial_translation';
        });

        $this->assertNotEmpty($partialTranslationDefects, 'Should detect partial translation defects');
        foreach ($partialTranslationDefects as $defect) {
            // Advisory only: the detector label never fails the verdict.
            $this->assertSame('warning', $defect['severity']);
        }
        $this->assertGreaterThan(
            0.05,
            $result['quality']['ratios']['partial_translation'],
            'The wrong-language ratio should still be measured'
        );
        $this->assertNull(
            $result['quality']['thresholds']['partial_translation'],
            'The wrong-language ratio must be advisory (no threshold)'
        );
    }

    public function testRegionalTargetTagMatchesBaseDetection(): void
    {
        // A correct German translation validated with a regional target tag
        // ("de-DE") must not flag partial translation: detection returns the
        // base code and both sides are compared on the base language
        // (the es-mx false-positive scenario, jobs 44919/45205/45207).
        $originalPath = $this->examplesDir . 'The.Matrix.1999.Tubi.CC.en.srt';
        $translationPath = $this->examplesDir . 'The.Matrix.1999.Tubi.CC.de.srt';

        $result = $this->validator->validate($originalPath, $translationPath, 'de-DE');

        $this->assertTrue($result['valid'], 'A regional target tag must not flip the verdict');
        $this->assertCount(
            0,
            array_filter($result['defects'], fn ($d) => $d['type'] === 'partial_translation'),
            'Base-code detection must match a regional target tag'
        );
    }

    public function testNonExistentFiles()
    {
        $result = $this->validator->validate(
            $this->examplesDir . 'nonexistent.srt',
            $this->examplesDir . 'nonexistent.srt',
            'de'
        );

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['defects']);
    }

    public function testMalformedSourceIsNotATranslationFailure(): void
    {
        // Invalid format in the SOURCE file (both plain garbage and real NUL
        // bytes) must never be flagged as a translation error: the verdict is
        // skipped with an informational warning instead.
        foreach (['plain garbage' => "this is not a subtitle file\nat all\n", 'nul bytes' => "1\n00:00:01,000 --> 00:00:03,000\n\x00binary\x00\n"] as $garbage) {
            $source = $this->examplesDir . 'tmp_bad_source.srt';
            file_put_contents($source, $garbage);
            try {
                $result = $this->validator->validate(
                    $source,
                    $this->examplesDir . 'The.Matrix.1999.Tubi.CC.de.srt',
                    'de'
                );

                $this->assertTrue($result['valid'], "A malformed source ({$garbage}) must never fail the translation");
                $this->assertSame(0, $result['error_count']);
                $this->assertSame(1, $result['warning_count']);
                $this->assertSame('source_parse_failed', $result['defects'][0]['type']);
                $this->assertSame('warning', $result['defects'][0]['severity']);
                $this->assertArrayNotHasKey('ratios', $result['quality'], 'No comparison runs against an unreadable source');
            } finally {
                @unlink($source);
            }
        }
    }

    public function testMalformedTranslationStillFails(): void
    {
        $translation = $this->examplesDir . 'tmp_bad_translation.srt';
        file_put_contents($translation, "1\n00:00:01,000 --> 00:00:03,000\n\x00binary\x00\n");
        try {
            $result = $this->validator->validate(
                $this->examplesDir . 'The.Matrix.1999.Tubi.CC.en.srt',
                $translation,
                'de'
            );

            $this->assertFalse($result['valid'], 'A malformed translation must still fail');
            $this->assertGreaterThan(0, $result['error_count']);
            $subtypes = array_map(function ($defect) {
                return $defect['subtype'] ?? null;
            }, $result['defects']);
            $this->assertContains('binary_content', $subtypes, 'NUL bytes in the translation output must be an error');
        } finally {
            @unlink($translation);
        }
    }

    public function testFormatMismatchComparesOnCueStructure(): void
    {
        $origPath = $this->examplesDir . 'tmp_mismatch.en.srt';
        $transPath = $this->examplesDir . 'tmp_mismatch.de.vtt';
        file_put_contents($origPath, "1\n00:00:01,000 --> 00:00:03,000\nHello world\n");
        file_put_contents($transPath, "WEBVTT\n\n00:00:01.000 --> 00:00:03.000\nHallo Welt\n");
        try {
            $result = $this->validator->validate($origPath, $transPath, 'de');

            $this->assertTrue($result['valid'], 'Cue-identical VTT translation of an SRT source must stay usable');
            $this->assertSame(0, $result['error_count']);
            $this->assertSame(1, $result['warning_count']);

            $mismatch = array_filter($result['defects'], function ($defect) {
                return $defect['type'] === 'format_mismatch';
            });
            $this->assertCount(1, $mismatch);
            $this->assertSame('warning', reset($mismatch)['severity']);
        } finally {
            @unlink($origPath);
            @unlink($transPath);
        }
    }

    public function testInvalidFormatDefect()
    {
        $originalPath = $this->examplesDir . 'The.Matrix.1999.Tubi.CC.en.srt';
        $translationPath = $this->examplesDir . 'defect_invalid_format.de.srt';

        $result = $this->validator->validate($originalPath, $translationPath, 'de');

        $this->assertFalse($result['valid'], 'Invalid format should fail validation');

        $invalidFormatDefects = array_filter($result['defects'], function ($defect) {
            return $defect['type'] === 'invalid_format';
        });

        $this->assertNotEmpty($invalidFormatDefects, 'Should detect invalid format defects');

        $subtypes = array_map(function ($defect) {
            return $defect['subtype'] ?? 'unknown';
        }, $invalidFormatDefects);

        $this->assertContains('missing_timestamp', $subtypes, 'Should detect a missing/expected timestamp line');
        $this->assertContains('malformed_timestamp', $subtypes, 'Should detect a malformed timestamp line');
        $this->assertSame(['missing_timestamp', 'missing_timestamp', 'missing_timestamp', 'malformed_timestamp', 'malformed_timestamp'], $subtypes, 'Should pinpoint each individual format error');
    }
}
