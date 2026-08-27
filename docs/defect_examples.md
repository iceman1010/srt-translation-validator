# Defect Examples Documentation

## Current Status

All defect files have been created using the automated tool (`create_defect_files.php`).

## Generated Defect Files

### 1. Partial Translation Defect
**File**: `examples/defect_partial_translation.de.srt`
- **Size**: ~124KB, 1834 captions, 7676 lines
- **Defect**: First 10 captions replaced with English text
- **Expected Detection**: Should trigger partial translation for captions 1-10
- **Current Result**: ❌ Not detected (language detection library limitation)

### 2. Missing Parts Defect  
**File**: `examples/defect_missing_parts.de.srt`
- **Size**: ~124KB, 1828 captions, 7653 lines
- **Defect**: Captions 100-105 removed (6 captions total)
- **Expected Detection**: Should report 6 missing captions
- **Current Result**: ✅ Working correctly

### 3. Timestamp Mismatch Defect
**File**: `examples/defect_timestamp_mismatch.de.srt`  
- **Size**: ~124KB, 1834 captions, 7679 lines
- **Defect**: Captions 200-209 timestamps shifted by +2.0 seconds
- **Expected Detection**: Should detect 10 timestamp mismatches
- **Current Result**: ✅ Working correctly (10 detected)

### 4. Invalid Format Defect
**File**: `examples/defect_invalid_format.de.srt`
- **Size**: ~101KB, 7680 lines
- **Defect**: Three format errors introduced at captions 100, 150, 200
- **Expected Detection**: Should detect invalid format issues
- **Current Result**: ❌ Parser tolerates malformed input

## Generation Tool

See `docs/defect-file-generator.md` for complete documentation on how the defect files were created.

## Test Results Summary

| Scenario | Expected | Actual | Status |
|----------|----------|--------|--------|
| Valid Translation | No defects | No defects | ✅ Pass |
| Missing Parts | 6 missing captions | 6 missing captions | ✅ Pass |
| Timestamp Mismatch | 10 mismatches | 10 mismatches | ✅ Pass |
| Invalid Format | Format errors | Not detected | ❌ Fail |
| Partial Translation | 10 English captions | Not detected | ❌ Fail |

## Source Files

- **Original (English)**: `examples/The.Matrix.1999.Tubi.CC.en.srt` (1834 captions)
- **Valid Translation (German)**: `examples/The.Matrix.1999.Tubi.CC.de.srt` (1834 captions)

## Validation Methods Working

| Detection Type | Status | Method | Test Coverage |
|---------------|---------|---------|---------------|
| Missing Parts | ✅ Working | Caption count comparison | ✅ Pass |
| Timestamp Mismatch | ✅ Working | 500ms tolerance comparison | ✅ Pass |
| Invalid Format | ⚠️ Partial | Parser error handling | ❌ Fail |
| Partial Translation | ❌ Not Working | 80-char chunking + 0.52 confidence | ❌ Fail |

## Known Issues

### Partial Translation Detection
The current language detection library (`patrickschur/language-detection`) has fundamental limitations:
- Low confidence scores (0.45-0.52 range) even for clear English text
- Misclassification of German as other languages (Afrikaans, Swedish, etc.)
- False positive concerns prevent lowering detection thresholds

### Invalid Format Detection
The subtitle parser (`mantas-done/subtitles`) is too tolerant:
- Ignores malformed timestamps
- Skips missing timestamps instead of failing
- Requires more aggressive error handling

## Recommended Improvements

1. **Install FastText** - See `docs/ToDo-Install-FastText.md` for 95%+ language detection accuracy
2. **Improve error handling** - Add stricter format validation
3. **Update test suite** - Add comprehensive tests once language detection is improved

## File Sizes Comparison

| File | Lines | Captions | Size |
|------|-------|----------|------|
| Original EN | 7684 | 1834 | ~110KB |
| Original DE | 7679 | 1834 | ~124KB |
| Partial Translation | 7676 | 1834 | ~124KB |
| Missing Parts | 7653 | 1828 | ~124KB |
| Timestamp Mismatch | 7679 | 1834 | ~124KB |
| Invalid Format | 7680 | 1834 | ~101KB |