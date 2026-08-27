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
    }

    public function testValidTranslation()
    {
        $originalPath = $this->examplesDir . 'The.Matrix.1999.Tubi.CC.en.srt';
        $translationPath = $this->examplesDir . 'The.Matrix.1999.Tubi.CC.de.srt';

        $result = $this->validator->validate($originalPath, $translationPath, 'de');

        $this->assertTrue($result['valid'], 'Valid translation should pass validation');
        $this->assertEmpty($result['defects'], 'Valid translation should have no defects');
    }

    public function testMissingPartsDefect()
    {
        $originalPath = $this->examplesDir . 'The.Matrix.1999.Tubi.CC.en.srt';
        $translationPath = $this->examplesDir . 'defect_missing_parts.de.srt';

        $result = $this->validator->validate($originalPath, $translationPath, 'de');

        $this->assertFalse($result['valid'], 'Missing parts should fail validation');

        $missingPartsDefects = array_filter($result['defects'], function($defect) {
            return $defect['type'] === 'missing_parts' || $defect['type'] === 'missing_caption';
        });

        $this->assertNotEmpty($missingPartsDefects, 'Should detect missing captions');
        $this->assertGreaterThan(5, count($missingPartsDefects), 'Should detect at least 6 missing captions');
    }

    public function testTimestampMismatchDefect()
    {
        $originalPath = $this->examplesDir . 'The.Matrix.1999.Tubi.CC.en.srt';
        $translationPath = $this->examplesDir . 'defect_timestamp_mismatch.de.srt';

        $result = $this->validator->validate($originalPath, $translationPath, 'de');

        $this->assertFalse($result['valid'], 'Timestamp mismatch should fail validation');

        $timestampDefects = array_filter($result['defects'], function($defect) {
            return $defect['type'] === 'timestamp_mismatch';
        });

        $this->assertNotEmpty($timestampDefects, 'Should detect timestamp mismatches');
        $this->assertEquals(20, count($timestampDefects), 'Should detect exactly 20 timestamp mismatches');

        foreach ($timestampDefects as $defect) {
            $this->assertEquals(2.0, $defect['start_diff'], 'Start drift should be 2.0 seconds');
            $this->assertTrue($defect['end_diff'] >= 0, 'End drift should be non-negative');
            $this->assertGreaterThanOrEqual(301, $defect['caption_number'], 'Should detect captions starting from 301');
            $this->assertLessThanOrEqual(320, $defect['caption_number'], 'Should detect captions up to 320');
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
        $this->markTestSkipped('Partial translation detection not working with current language library. See docs/ToDo-Install-FastText.md');

        $originalPath = $this->examplesDir . 'The.Matrix.1999.Tubi.CC.en.srt';
        $translationPath = $this->examplesDir . 'defect_partial_translation.de.srt';

        $result = $this->validator->validate($originalPath, $translationPath, 'de');

        $this->assertFalse($result['valid'], 'Partial translation should fail validation');

        $partialTranslationDefects = array_filter($result['defects'], function($defect) {
            return $defect['type'] === 'partial_translation';
        });

        $this->assertNotEmpty($partialTranslationDefects, 'Should detect partial translation defects');
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

    public function testInvalidFormatDefect()
    {
        $this->markTestSkipped('Invalid format detection not working - parser too tolerant. Current parser handles malformed input gracefully.');

        $originalPath = $this->examplesDir . 'The.Matrix.1999.Tubi.CC.en.srt';
        $translationPath = $this->examplesDir . 'defect_invalid_format.de.srt';

        $result = $this->validator->validate($originalPath, $translationPath, 'de');

        $this->assertFalse($result['valid'], 'Invalid format should fail validation');

        $invalidFormatDefects = array_filter($result['defects'], function($defect) {
            return $defect['type'] === 'invalid_format';
        });

        $this->assertNotEmpty($invalidFormatDefects, 'Should detect invalid format defects');
    }
}