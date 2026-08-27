<?php

require 'vendor/autoload.php';

echo "Creating proper defect files using subtitle library...\n";

// Load original files
$originalEn = Done\Subtitles\Subtitles::loadFromFile('examples/The.Matrix.1999.Tubi.CC.en.srt');
$originalDe = Done\Subtitles\Subtitles::loadFromFile('examples/The.Matrix.1999.Tubi.CC.de.srt');

$enBlocks = $originalEn->getInternalFormat();
$deBlocks = $originalDe->getInternalFormat();
$totalCaptions = count($deBlocks);

echo "Total captions in original: $totalCaptions\n";

// ============================================
// 1. Partial Translation - LARGE SCALE (half the file in wrong language)
// This simulates a real scenario: translator forgot to translate the second half
// ============================================
echo "Creating partial translation file (captions 900-1834 in English)...\n";
$partialDe = clone $originalDe;
$partialBlocks = $partialDe->getInternalFormat();

// Make captions 900-1834 (about half) in English instead of German
for ($i = 900; $i < $totalCaptions && $i < count($partialBlocks); $i++) {
    // Use corresponding English caption text
    $partialBlocks[$i]['lines'] = $enBlocks[$i]['lines'] ?? ["ENGLISH TEXT " . ($i + 1)];
}

$partialDe->setInternalFormat($partialBlocks);
$partialDe->save('examples/defect_partial_translation.de.srt');
echo "Created defect_partial_translation.de.srt - captions 900-$totalCaptions in English (about 934 captions)\n";

// ============================================
// 2. Missing Parts - Remove a small block of captions
// Realistic: a block of 6 captions missing from the middle
// ============================================
echo "Creating missing parts file (captions 500-505 removed)...\n";
$missingDe = clone $originalDe;
$missingBlocks = $missingDe->getInternalFormat();

// Remove captions 500-505 (indices 499-504) - a small block in the middle
$missingBlocks = array_merge(
    array_slice($missingBlocks, 0, 499),
    array_slice($missingBlocks, 505)
);

$missingDe->setInternalFormat($missingBlocks);
$missingDe->save('examples/defect_missing_parts.de.srt');
echo "Created defect_missing_parts.de.srt with " . count($missingBlocks) . " captions (removed 6 from middle)\n";

// ============================================
// 3. Timestamp Mismatch - Shift a small range
// Realistic: a small block has timing issues
// ============================================
echo "Creating timestamp mismatch file (captions 300-319 shifted by +2s)...\n";
$timestampDe = clone $originalDe;
$timestampBlocks = $timestampDe->getInternalFormat();

// Shift captions 300-319 by +2.0 seconds
for ($i = 300; $i < 320 && $i < count($timestampBlocks); $i++) {
    $timestampBlocks[$i]['start'] += 2.0;
    $timestampBlocks[$i]['end'] += 2.0;
}

$timestampDe->setInternalFormat($timestampBlocks);
$timestampDe->save('examples/defect_timestamp_mismatch.de.srt');
echo "Created defect_timestamp_mismatch.de.srt with timestamps shifted for captions 300-319\n";

// ============================================
// 4. Invalid Format - Clear format errors
// Realistic: a few specific format violations
// ============================================
echo "Creating invalid format file...\n";

function toSrtTimecode(float $seconds): string
{
    $ms = (int)round(($seconds - (int)$seconds) * 1000);
    return sprintf(
        '%02d:%02d:%02d,%03d',
        (int)floor($seconds / 3600),
        (int)floor(($seconds % 3600) / 60),
        (int)($seconds % 60),
        $ms
    );
}

$invalidContent = [];

for ($i = 0; $i < count($deBlocks); $i++) {
    $block = $deBlocks[$i];
    $timecode = toSrtTimecode($block['start']) . ' --> ' . toSrtTimecode($block['end']);

    // Add format errors at specific points
    if ($i === 500) {
        // Missing timestamp line entirely
        $invalidContent[] = ($i + 1);
        $invalidContent[] = "MISSING TIMESTAMP LINE HERE";
        $invalidContent[] = implode(' ', $block['lines']);
        $invalidContent[] = "";
    } elseif ($i === 800) {
        // Invalid timestamp format (looks like a timestamp but is not)
        $invalidContent[] = ($i + 1);
        $invalidContent[] = "NOT_A_VALID_TIMESTAMP_FORMAT";
        $invalidContent[] = implode(' ', $block['lines']);
        $invalidContent[] = "";
    } elseif ($i === 1200) {
        // Missing sequence number + malformed timestamp (5 segment groups)
        $invalidContent[] = "MISSING SEQUENCE NUMBER";
        $invalidContent[] = "00:00:00:0000 --> 00:00:00:0000";
        foreach ($block['lines'] as $line) {
            $invalidContent[] = $line;
        }
        $invalidContent[] = "";
    } elseif ($i === 1500) {
        // Timestamp with wrong milliseconds separator (dot in SRT is a variant,
        // but here we use a clearly broken timecode with 4 segment groups)
        $invalidContent[] = ($i + 1);
        $invalidContent[] = "00:00:00.000 --> 00:00:00:0000";
        $invalidContent[] = implode(' ', $block['lines']);
        $invalidContent[] = "";
    } else {
        // Normal format
        $invalidContent[] = ($i + 1);
        $invalidContent[] = $timecode;
        foreach ($block['lines'] as $line) {
            $invalidContent[] = $line;
        }
        $invalidContent[] = "";
    }
}

file_put_contents('examples/defect_invalid_format.de.srt', implode("\n", $invalidContent));
echo "Created defect_invalid_format.de.srt with format errors at captions 500, 800, 1200, 1500\n";

echo "\nAll defect files created successfully!\n";

// Quick verification
echo "\nVerification:\n";
$p = Done\Subtitles\Subtitles::loadFromFile('examples/defect_partial_translation.de.srt');
echo "Partial translation: " . count($p->getInternalFormat()) . " captions (first 900 German, rest English)\n";

$m = Done\Subtitles\Subtitles::loadFromFile('examples/defect_missing_parts.de.srt');
echo "Missing parts: " . count($m->getInternalFormat()) . " captions (6 removed)\n";

$t = Done\Subtitles\Subtitles::loadFromFile('examples/defect_timestamp_mismatch.de.srt');
echo "Timestamp mismatch: " . count($t->getInternalFormat()) . " captions (20 shifted by +2s)\n";

// Check first few captions of partial translation to verify
$pBlocks = $p->getInternalFormat();
echo "\nPartial translation verification:\n";
echo "  Caption 899: " . implode(' ', $pBlocks[898]['lines']) . "\n";
echo "  Caption 900: " . implode(' ', $pBlocks[899]['lines']) . "\n";
echo "  Caption 901: " . implode(' ', $pBlocks[900]['lines']) . "\n";
echo "  Caption $totalCaptions: " . implode(' ', $pBlocks[$totalCaptions-1]['lines']) . "\n";

echo "\nAll defect files created successfully!\n";