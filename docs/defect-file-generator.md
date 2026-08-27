# Defect Files Generator Tool

This document explains the defect file generation tool used to create proper test files for the SRT translation validator.

## Purpose

Create realistic defect test files that:
- Match the original file structure (caption count, timestamps)
- Test specific defect types in isolation
- Avoid introducing massive unrelated issues (like 1800+ missing captions)

## Usage

```bash
php create_defect_files.php
```

## How It Works

### 1. Loads Original Files
- Loads `examples/The.Matrix.1999.Tubi.CC.en.srt` (English original)
- Loads `examples/The.Matrix.1999.Tubi.CC.de.srt` (German translation)

### 2. Generates Four Defect Types

#### Partial Translation (`defect_partial_translation.de.srt`)
- **Defect**: First 10 captions replaced with English text
- **Structure**: Maintains original 1834 captions and timestamps
- **Detection target**: Should trigger partial translation detection for captions 1-10

#### Missing Parts (`defect_missing_parts.de.srt`)
- **Defect**: Captions 100-105 removed (6 captions total)
- **Structure**: 1828 captions, timestamps preserved for remaining captions
- **Detection target**: Should trigger missing parts detection

#### Timestamp Mismatch (`defect_timestamp_mismatch.de.srt`)
- **Defect**: Captions 200-209 timestamps shifted by +2.0 seconds
- **Structure**: Maintains original 1834 captions
- **Detection target**: Should trigger timestamp mismatch for captions 200-209

#### Invalid Format (`defect_invalid_format.de.srt`)
- **Defect**: Three format errors introduced:
  - Caption 100: Missing timestamp
  - Caption 150: Invalid timestamp format "INVALID:TIMESTAMP:FORMAT"
  - Caption 200: Extra text before caption number
- **Structure**: Maintains caption count but with format violations
- **Detection target**: Should trigger invalid format detection

## Key Features

### Uses Subtitle Library
```php
require 'vendor/autoload.php';
$original = Done\Subtitles\Subtitles::loadFromFile('examples/The.Matrix.1999.Tubi.CC.de.srt');
$blocks = $original->getInternalFormat();
```

### Proper File Saving
```php
$subtitleObj->setInternalFormat($modifiedBlocks);
$subtitleObj->save('examples/defect_filename.de.srt');
```

### Verification
The script automatically verifies created files by:
- Loading them back with the subtitle library
- Reporting caption counts
- Ensuring proper SRT structure

## Why Manual Creation Failed

### Problems with Manual Approach:
1. **File structure complexity**: SRT format requires specific spacing, timestamps, and numbering
2. **Large scale**: 1834 captions make manual editing error-prone
3. **Library conflicts**: Manual text manipulation didn't work with the subtitle parser
4. **Format inconsistencies**: Generated files had wrong caption sequences and structure

### Tool Benefits:
1. **Preserves structure**: Uses subtitle library to maintain proper format
2. **Scalable**: Can easily modify ranges and defect types
3. **Reliable**: Library handles formatting and validation
4. **Testable**: Can regenerate files with different parameters

## Current Detection Results

| File | Expected Detection | Current Result | Issue |
|------|-------------------|----------------|-------|
| `defect_partial_translation.de.srt` | 10 captions English | 0 defects detected | Language detection too unreliable |
| `defect_missing_parts.de.srt` | 6 missing captions | ✅ Detected | Works correctly |
| `defect_timestamp_mismatch.de.srt` | 10 timestamps shifted | ✅ Detected (10) | Works correctly |
| `defect_invalid_format.de.srt` | 3 format errors | ❌ Not detected | Parser tolerates malformed input |

## Extending the Tool

### Adding New Defect Types

Modify the script to add new defect patterns:

```php
// Example: Create file with duplicate timestamps
$duplicateDe = clone $originalDe;
$duplicateBlocks = $duplicateDe->getInternalFormat();

// Set caption 50 timestamp same as caption 49
$duplicateBlocks[50]['start'] = $duplicateBlocks[49]['start'];
$duplicateBlocks[50]['end'] = $duplicateBlocks[49]['end'];

$duplicateDe->setInternalFormat($duplicateBlocks);
$duplicateDe->save('examples/defect_duplicate_timestamps.de.srt');
```

### Customizing Ranges

Change the caption ranges in each section:

```php
// Modify partial translation range
for ($i = 50; $i < 70 && $i < count($partialBlocks); $i++) {
    $partialBlocks[$i]['lines'] = [$englishReplacements[$i - 50]];
}

// Modify timestamp shift range
for ($i = 300; $i < 350 && $i < count($timestampBlocks); $i++) {
    $timestampBlocks[$i]['start'] += 1.5;
    $timestampBlocks[$i]['end'] += 1.5;
}
```

## Testing Generated Files

After running the script, test with the validator:

```bash
php -r "
require 'vendor/autoload.php';
\$v = new \SrtValidator\SrtTranslationValidator();
\$result = \$v->validate('examples/The.Matrix.1999.Tubi.CC.en.srt', 
                        'examples/defect_partial_translation.de.srt', 'de');
echo 'Defects: ' . count(\$result['defects']) . PHP_EOL;
"
```

## Maintenance

### When to Regenerate:
- After updating the original subtitle files
- When testing new defect detection thresholds
- When modifying validation logic

### Backup Original Files
Before regenerating, backup the working defect files:

```bash
cp examples/defect_*.srt examples/defect_*.backup.srt
```

## Integration with Test Suite

The generated files are used in `tests/SrtTranslationValidatorTest.php`:

```php
public function testPartialTranslationDefect()
{
    $originalPath = $this->examplesDir . 'The.Matrix.1999.Tubi.CC.en.srt';
    $translationPath = $this->examplesDir . 'defect_partial_translation.de.srt';
    
    $result = $this->validator->validate($originalPath, $translationPath, 'de');
    
    $this->assertFalse($result['valid'], 'Partial translation should fail validation');
    $this->assertGreaterThanOrEqual(10, count($result['defects']));
}
```

## Technical Details

### File Sizes Generated:
- `defect_partial_translation.de.srt`: ~101KB
- `defect_missing_parts.de.srt`: ~100KB  
- `defect_timestamp_mismatch.de.srt`: ~101KB
- `defect_invalid_format.de.srt`: ~98KB

### Generation Time:
- ~2 seconds for all 4 files
- Depends on system I/O speed

### Dependencies:
- `mantas-done/subtitles` package
- Original source files in `examples/`
- PHP 7.4+ or 8.x

## Troubleshooting

### Files Not Created
- Check original files exist in `examples/`
- Verify composer dependencies installed
- Check write permissions on `examples/` directory

### Wrong Caption Count
- Original file may have been modified
- Re-download source files if needed
- Regenerate using the tool

### Format Errors in Generated Files
- Update subtitle library version
- Check library compatibility with SRT format
- Report library bugs if found

## Related Files

- `composer.json` - Subtitle library dependency
- `src/SrtTranslationValidator.php` - Main validator class
- `tests/SrtTranslationValidatorTest.php` - Test suite using generated files
- `docs/ToDo-Install-FastText.md` - Future improvement: better language detection