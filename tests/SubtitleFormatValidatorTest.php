<?php

use PHPUnit\Framework\TestCase;
use SrtValidator\SubtitleFormatValidator;

class SubtitleFormatValidatorTest extends TestCase
{
    private $validator;

    protected function setUp(): void
    {
        $this->validator = new SubtitleFormatValidator();
    }

    private function assertValid(string $content, string $expectedFormat = SubtitleFormatValidator::FORMAT_SRT, int $expectedCues = 0): void
    {
        $result = $this->validator->validateContent($content);
        $this->assertSame($expectedFormat, $result['format'], 'Detected format should match');
        $this->assertTrue($result['valid'], 'File should be valid: ' . json_encode($result['errors']));
        if ($expectedCues > 0) {
            $this->assertSame($expectedCues, $result['cue_count'], 'Cue count should match');
        }
    }

    private function assertInvalid(string $content, string $subtype): array
    {
        $result = $this->validator->validateContent($content);
        $this->assertFalse($result['valid'], 'File should be invalid');
        $this->assertNotEmpty($result['errors'], 'Should produce at least one error');
        $matches = array_filter($result['errors'], function ($e) use ($subtype) {
            return $e['subtype'] === $subtype;
        });
        $this->assertNotEmpty($matches, "Should contain a '{$subtype}' error: " . json_encode($result['errors']));
        return $result;
    }

    // ---------------------------------------------------------------
    // Format detection
    // ---------------------------------------------------------------

    public function testDetectSrt(): void
    {
        $this->assertSame('srt', $this->validator->detectFormat("1\n00:00:01,000 --> 00:00:02,000\nHello\n"));
        $this->assertSame('srt', $this->validator->detectFormat("\xEF\xBB\xBF1\n00:00:01,000 --> 00:00:02,000\nHello\n"));
    }

    public function testDetectVtt(): void
    {
        $this->assertSame('vtt', $this->validator->detectFormat("WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nHello\n"));
        $this->assertSame('vtt', $this->validator->detectFormat("\xEF\xBB\xBFWEBVTT\n\n00:00:01.000 --> 00:00:02.000\nHello\n"));
        $this->assertSame('vtt', $this->validator->detectFormat("WEBVTT - English subtitles\n\n00:00:01.000 --> 00:00:02.000\nHello\n"));
    }

    public function testDetectEmptyContent(): void
    {
        $this->assertNull($this->validator->detectFormat(''));
        $this->assertNull($this->validator->detectFormat("\n\n  \n"));
    }

    // ---------------------------------------------------------------
    // SRT valid variants
    // ---------------------------------------------------------------

    public function testStandardSrtIsValid(): void
    {
        $content = <<<SRT
1
00:00:01,000 --> 00:00:03,000
Hello world

2
00:00:04,000 --> 00:00:06,000
Second line
with two text lines

SRT;
        $this->assertValid($content, 'srt', 2);
    }

    public function testSrtWithDotMillisecondsIsValid(): void
    {
        $content = <<<SRT
1
00:00:01.000 --> 00:00:03.000
Dot separator variant

2
00:00:04.500 --> 00:00:06.250
Still valid

SRT;
        $this->assertValid($content, 'srt', 2);
    }

    public function testSrtWithoutSequenceNumbersIsValid(): void
    {
        $content = <<<SRT
00:00:01,000 --> 00:00:03,000
No numbering here

00:00:04,000 --> 00:00:06,000
Second cue without number

SRT;
        $this->assertValid($content, 'srt', 2);
    }

    public function testSrtNumberOnSameLineAsTimestampIsValid(): void
    {
        $content = <<<SRT
0 00:00:00,000 --> 00:00:00,850
Subconvert-style cue

1 00:00:01,000 --> 00:00:03,549
Number and time on one line

SRT;
        $this->assertValid($content, 'srt', 2);
    }

    public function testSrtWithCoordinateExtensionsIsValid(): void
    {
        $content = <<<SRT
1
00:00:01,000 --> 00:00:03,000 X1:100 X2:200 Y1:300 Y2:400
Text with coordinates

SRT;
        $this->assertValid($content, 'srt', 1);
    }

    public function testSrtWithBomAndCrlfIsValid(): void
    {
        $content = "\xEF\xBB\xBF1\r\n00:00:01,000 --> 00:00:03,000\r\nWindows line endings\r\n\r\n2\r\n00:00:04,000 --> 00:00:06,000\r\nSecond cue\r\n";
        $this->assertValid($content, 'srt', 2);
    }

    public function testSrtWithoutTrailingBlankLineIsValid(): void
    {
        $content = "1\n00:00:01,000 --> 00:00:03,000\nText\n\n2\n00:00:04,000 --> 00:00:06,000\nNo blank after this";
        $this->assertValid($content, 'srt', 2);
    }

    public function testSrtWithExtraBlankLinesAndHtmlIsValid(): void
    {
        $content = <<<SRT
1
00:00:01,000 --> 00:00:03,000
<i>Italic</i> and <b>bold</b>


2
00:00:04,000 --> 00:00:06,000
[SOUND] and ♪ music symbols


SRT;
        $this->assertValid($content, 'srt', 2);
    }

    public function testSrtDialogueWithBareNumberLineIsValid(): void
    {
        $content = <<<SRT
1
00:00:01,000 --> 00:00:03,000
The number is

2
00:00:04,000 --> 00:00:06,000

3
00:00:07,000 --> 00:00:09,000
42
and some more text

SRT;
        // Cue 2 is empty (warning only); cue 3's bare "42" is cue *text* and
        // must not be mistaken for a sequence number.
        $result = $this->validator->validateContent($content);
        $this->assertTrue($result['valid'], 'Valid despite empty cue: ' . json_encode($result['errors']));
        $this->assertSame(3, $result['cue_count']);
        $hasEmpty = array_filter($result['warnings'], function ($w) {
            return $w['subtype'] === 'empty_cue';
        });
        $this->assertNotEmpty($hasEmpty, 'Empty cue should be reported as a warning');
    }

    // ---------------------------------------------------------------
    // SRT invalid
    // ---------------------------------------------------------------

    public function testSrtWithMalformedTimestampLineIsInvalid(): void
    {
        $content = <<<SRT
1
NOT_A_VALID_TIMESTAMP_FORMAT
Some text

SRT;
        $result = $this->assertInvalid($content, 'missing_timestamp');
        $this->assertSame(2, $result['errors'][0]['line']);
    }

    public function testSrtWithFiveSegmentTimestampIsInvalid(): void
    {
        $content = <<<SRT
1
00:00:00:0000 --> 00:00:00:0000
Bad five-segment timecode

SRT;
        $this->assertInvalid($content, 'malformed_timestamp');
    }

    public function testSrtTimestampMissingMillisecondsIsInvalid(): void
    {
        $content = <<<SRT
1
00:00:01,000 --> 00:00:03
Milliseconds missing from end

SRT;
        $this->assertInvalid($content, 'malformed_timestamp');
    }

    public function testSrtWithMissingTimestampAfterNumberIsInvalid(): void
    {
        $content = <<<SRT
1
00:00:01,000 --> 00:00:03,000
First cue

2
PROBLEM - NO TIMESTAMP LINE HERE
Broken cue text

3
00:00:07,000 --> 00:00:09,000
Third cue is fine

SRT;
        $result = $this->assertInvalid($content, 'missing_timestamp');
        $this->assertArrayHasKey('line', $result['errors'][0]);
    }

    public function testSrtGarbageBeforeFirstCueIsInvalid(): void
    {
        $content = <<<SRT
This is a header that should not be here

1
00:00:01,000 --> 00:00:03,000
Text

SRT;
        $this->assertInvalid($content, 'missing_timestamp');
    }

    public function testSrtOutOfOrderNumberingOnlyWarns(): void
    {
        $content = <<<SRT
1
00:00:01,000 --> 00:00:03,000
First

3
00:00:04,000 --> 00:00:06,000
Number 3

2
00:00:07,000 --> 00:00:09,000
Number 2 out of order

SRT;
        $result = $this->validator->validateContent($content);
        $this->assertTrue($result['valid'], 'Numbering order is a warning, not a structural error');
        $hasOrder = array_filter($result['warnings'], function ($w) {
            return $w['subtype'] === 'out_of_order_numbering';
        });
        $this->assertNotEmpty($hasOrder, 'Out-of-order numbering should be reported');
    }

    public function testSrtBinaryContentIsInvalid(): void
    {
        $content = "1\n00:00:01,000 --> 00:00:03,000\n\x00\x00\x00binary\x00\n";
        $this->assertInvalid($content, 'binary_content');
    }

    // ---------------------------------------------------------------
    // VTT valid variants
    // ---------------------------------------------------------------

    public function testMinimalVttIsValid(): void
    {
        $content = <<<VTT
WEBVTT

00:00:01.000 --> 00:00:03.000
Hello from WebVTT

00:00:04.000 --> 00:00:06.000
Second cue

VTT;
        $this->assertValid($content, 'vtt', 2);
    }

    public function testVttWithoutHoursIsValid(): void
    {
        $content = <<<VTT
WEBVTT

00:10.000 --> 00:20.000
Minutes and seconds only

VTT;
        $this->assertValid($content, 'vtt', 1);
    }

    public function testVttWithIdentifierAndSettingsIsValid(): void
    {
        $content = <<<VTT
WEBVTT

intro
00:00:01.000 --> 00:00:03.000 align:middle position:50% line:0
Positioned cue text

chapter-2
00:00:04.000 --> 00:00:06.000 region:fred size:80%
Region cue

VTT;
        $this->assertValid($content, 'vtt', 2);
    }

    public function testVttWithRegionsStylesAndNotesIsValid(): void
    {
        $content = <<<VTT
WEBVTT - test file

NOTE This is a comment
spanning two lines

STYLE
::cue {
  background-color: rgba(0,0,0,0.8);
  color: white;
}

REGION
id:fred
width:40%
lines:3
regionanchor:0%,100%
viewportanchor:10%,90%

NOTE Another comment

00:00:01.000 --> 00:00:03.000
Cue after header blocks

VTT;
        $this->assertValid($content, 'vtt', 1);
    }

    public function testVttWithBomAndCrlfIsValid(): void
    {
        $content = "\xEF\xBB\xBFWEBVTT\r\n\r\n00:00:01.000 --> 00:00:03.000\r\nWindows VTT\r\n\r\n00:00:04.000 --> 00:00:06.000\r\nSecond\r\n";
        $this->assertValid($content, 'vtt', 2);
    }

    public function testVttWithNegativeStartIsValid(): void
    {
        $content = <<<VTT
WEBVTT

-00:00:01.000 --> 00:00:02.000
Pre-roll cue

VTT;
        $this->assertValid($content, 'vtt', 1);
    }

    public function testVttEmptyCueOnlyWarns(): void
    {
        $content = <<<VTT
WEBVTT

00:00:01.000 --> 00:00:03.000


VTT;
        $result = $this->validator->validateContent($content);
        $this->assertTrue($result['valid']);
        $this->assertSame(1, $result['cue_count']);
    }

    // ---------------------------------------------------------------
    // VTT invalid
    // ---------------------------------------------------------------

    public function testVttWithoutHeaderIsInvalid(): void
    {
        $content = <<<VTT
00:00:01.000 --> 00:00:03.000
No WEBVTT header

VTT;
        $this->assertInvalid($content, 'missing_webvtt_header');
    }

    public function testVttWithCommaMillisecondsIsInvalid(): void
    {
        $content = <<<VTT
WEBVTT

00:00:01,000 --> 00:00:03,000
Comma is SRT, not WebVTT

VTT;
        $this->assertInvalid($content, 'malformed_timestamp');
    }

    public function testVttWithStrayTextIsInvalid(): void
    {
        $content = <<<VTT
WEBVTT

This text has no cue timings before it

VTT;
        $this->assertInvalid($content, 'stray_text');
    }

    public function testVttIdentifierWithoutTimingsIsInvalid(): void
    {
        $content = <<<VTT
WEBVTT

some-identifier
followed by plain text, no timings

VTT;
        $result = $this->assertInvalid($content, 'stray_text');
    }

    public function testExtensionMismatchWarns(): void
    {
        $srt = "1\n00:00:01,000 --> 00:00:03,000\nHello\n";
        $result = $this->validator->validateContent($srt, 'vtt');
        $this->assertTrue($result['valid'], 'Format itself is fine, extension mismatch is only a warning');
        $hasMismatch = array_filter($result['warnings'], function ($w) {
            return $w['subtype'] === 'extension_mismatch';
        });
        $this->assertNotEmpty($hasMismatch);

        $vtt = "WEBVTT\n\n00:00:01.000 --> 00:00:03.000\nHello\n";
        $result = $this->validator->validateContent($vtt, 'srt');
        $hasMismatch = array_filter($result['warnings'], function ($w) {
            return $w['subtype'] === 'extension_mismatch';
        });
        $this->assertNotEmpty($hasMismatch);
    }

    // ---------------------------------------------------------------
    // Files on disk
    // ---------------------------------------------------------------

    public function testRealValidSrtFilesOnDisk(): void
    {
        $dir = __DIR__ . '/../examples/';
        foreach (['The.Matrix.1999.Tubi.CC.en.srt', 'The.Matrix.1999.Tubi.CC.de.srt'] as $file) {
            $result = $this->validator->validateFile($dir . $file);
            $this->assertTrue($result['valid'], "{$file} should be structurally valid: " . json_encode($result['errors']));
            $this->assertSame('srt', $result['format']);
            $this->assertSame(1834, $result['cue_count']);
        }
    }

    public function testFormatVariantFilesOnDisk(): void
    {
        $dir = __DIR__ . '/../examples/';
        foreach (['defect_missing_parts.de.srt', 'defect_timestamp_mismatch.de.srt', 'defect_partial_translation.de.srt'] as $file) {
            $result = $this->validator->validateFile($dir . $file);
            $this->assertTrue($result['valid'], "{$file} should be structurally valid: " . json_encode($result['errors']));
        }

        $result = $this->validator->validateFile($dir . 'defect_invalid_format.de.srt');
        $this->assertFalse($result['valid'], 'Invalid format file should be flagged');
    }

    public function testMissingFileOnDisk(): void
    {
        $result = $this->validator->validateFile('/nonexistent/nope.srt');
        $this->assertFalse($result['valid']);
        $this->assertSame('unreadable_file', $result['errors'][0]['subtype']);
    }
}