# SRT Translation Validator

A PHP tool that validates subtitle translations. It compares an **original**
subtitle file against its **translation** and reports four kinds of defects:

| Defect                | What it detects                                                          |
| --------------------- | ------------------------------------------------------------------------ |
| `invalid_format`      | Malformed SRT/WebVTT structure (bad or missing timestamp lines, garbage) |
| `missing_parts`       | Captions present in the original but absent in the translation           |
| `partial_translation` | Large caption blocks detected in the wrong language (e.g. left untranslated) |
| `timestamp_mismatch`  | Captions whose start/end times drifted beyond a tolerance                |

It ships both as a **Composer library** and as a **standalone command-line
tool** (the `bin/srt-validator` entry point, also packaged as a PHAR).

## Requirements

- PHP 7.4+ (8.x recommended)
- [Composer](https://getcomposer.org/)
- Extensions: `mbstring`, and for PHAR builds `phar` + `zlib`

## Installation

```bash
composer require srt/translation-validator
```

or, from this repository:

```bash
composer install
```

## Command-line usage

The CLI accepts exactly two positional arguments – the two subtitle files –
compares them, and prints a human-readable report.

```bash
php bin/srt-validator <original-file> <translation-file> [options]
```

When this package is installed as a dependency of another project, Composer
publishes a proxy so the short form works too:

```bash
srt-validator <original-file> <translation-file> [options]
```

### Options

| Option                 | Description                                                              |
| ---------------------- | ------------------------------------------------------------------------ |
| `-l, --lang=CODE`      | Expected language of the translation (ISO 639-1, e.g. `de`). Default: auto-detected from the translation text. |
| `-t, --tolerance=SEC`  | Timestamp drift tolerance in seconds (default: `0.5`).                   |
| `-h, --help`           | Show usage help.                                                         |

### Exit codes

| Code | Meaning                                                     |
| ---- | ----------------------------------------------------------- |
| `0`  | The translation is valid (no defects found).                 |
| `1`  | Defects were found – the translation is invalid.             |
| `2`  | Usage error, or the validator could not run.                 |

### Examples

```bash
# Compare and auto-detect the translation language
php bin/srt-validator original.srt translation.de.srt

# Explicit expected language
srt-validator original.srt translation.srt -l de

# Stricter timestamp tolerance (100 ms)
srt-validator -t 0.1 -l de Movie.en.srt Movie.pt.srt
```

### Example report

```
==================================================================
  Subtitle Translation Validator
==================================================================

  Original:           examples/The.Matrix.1999.Tubi.CC.en.srt
  Translation:        examples/defect_timestamp_mismatch.de.srt
  Expected language:  de
  Timestamp tolerance: 0.5s

  Defects (20)
------------------------------------------------------------------

  1. TIMESTAMP MISMATCH [caption #301]
Caption #301 has timestamp drift
original   : 00:22:08,627 --> 00:22:14,066
translation: 00:22:10,627 --> 00:22:16,066
drift      : start +2.000s, end +2.000s

  ...

==================================================================
  RESULT: FAILED - 20 defect(s) found
==================================================================
```

A valid translation prints `RESULT: PASSED` and exits `0`.

## Using the library in PHP

### Full validation

```php
<?php

require 'vendor/autoload.php';

use SrtValidator\SrtTranslationValidator;

$validator = new SrtTranslationValidator();

// Tune timestamp drift tolerance (default is 0.5 seconds)
$validator->setTimestampTolerance(0.2);

$result = $validator->validate(
    'path/to/original.srt',   // reference subtitles
    'path/to/translation.srt', // the translation being checked
    'de'                       // expected translation language
);

if ($result['valid']) {
    echo "Translation is OK.\n";
    exit(0);
}

foreach ($result['defects'] as $i => $defect) {
    printf(
        "%d. [%s] %s\n",
        $i + 1,
        strtoupper(str_replace('_', ' ', $defect['type'])),
        $defect['message']
    );
}
```

The `defects` array returned by `validate()` looks like this:

```php
[
    'valid'   => false,
    'defects' => [
        [
            'type'    => 'missing_caption',
            'message' => 'Caption #505 is missing in translation',
            'caption_number' => 505,
            'original_text' => 'Neo?',
        ],
        [
            'type'    => 'partial_translation',
            'message' => 'Large block (captions 901-1400) detected as en instead of de - ...',
            'start_caption' => 901,
            'end_caption'   => 1400,
            'detected_language' => 'en',
            'confidence'    => 0.88,
        ],
        [
            'type'    => 'timestamp_mismatch',
            'caption_number' => 301,
            'message' => 'Caption #301 has timestamp drift',
            'original_start'     => 1334.627,
            'translation_start'  => 1336.627,
            'start_diff' => 2.0,
        ],
        [
            'type'    => 'invalid_format',
            'line'    => 2112,
            'subtype' => 'missing_timestamp',
            'message' => 'Line 2112: expected a timing line (...) ...',
        ],
    ],
]
```

### Validation of file format only

If you only need to check the SRT/WebVTT structure of one file:

```php
<?php

require 'vendor/autoload.php';

use SrtValidator\SubtitleFormatValidator;

$validator = new SubtitleFormatValidator();

// Content already in memory:
$result = $validator->validateContent($srtContent, 'srt');

// Or a file on disk:
$result = $validator->validateFile('subtitles.srt');

printf(
    "format=%s valid=%s cues=%d\n",
    $result['format'],                  // 'srt' or 'vtt'
    $result['valid'] ? 'yes' : 'no',
    $result['cue_count']
);

foreach ($result['errors'] as $error) {
    printf("  line %d [%s] %s\n", $error['line'], $error['subtype'], $error['message']);
}
```

The format validator understands every widespread SRT/WebVTT variant:
comma or dot milliseconds, sequence numbers on their own or the timing line,
un-numbered cues, `X1:Y1` coordinate extensions, BOM and CRLF/CR line
endings, and for WebVTT: cue identifiers/settings, `NOTE`, `STYLE`, `REGION`
blocks and negative timestamps.

## Building the PHAR

A self-contained `srt-translation-validator.phar` (no Composer needed to run
it) can be built from this repository:

```bash
composer install          # dev dependencies (only needed for tests)
php -d phar.readonly=0 build/build-phar.php
```

or via the Composer script:

```bash
composer run build-phar
```

The PHAR bundles `src/`, `bin/` and the production `vendor/` tree, so it runs
on any machine with PHP 7.4+:

```bash
php build/srt-translation-validator.phar original.srt translation.srt -l de
# or, it is executable:
./build/srt-translation-validator.phar original.srt translation.srt
```

## Continuous integration / release

The repository ships a GitHub Actions workflow (`.github/workflows/build-phar.yml`)
that:

1. Runs the PHPUnit suite on PHP 8.1, 8.2 and 8.3.
2. Builds the PHAR from a `--no-dev` install.
3. Verifies the PHAR signature and smoke-tests it against real comparisons
   (valid → exit 0, missing captions → exit 1, malformed format → exit 1).
4. Uploads the PHAR as a workflow artifact on every push/PR.
5. Attaches the PHAR to a GitHub *Release* when a tag such as `v1.0.0` is
   pushed. Versions are then downloadable from
   `<repo>/releases/latest`.

## Tests

```bash
composer test
# or
vendor/bin/phpunit tests/
```

The suite contains structure/format tests, library integration tests, and
end-to-end CLI tests. Most are self-contained; the tests that rely on the
large example subtitle files automatically skip when those local fixtures are
not checked out.

## Project layout

```
src/                          Library classes (PSR-4: SrtValidator\)
  SrtTranslationValidator.php Full translation validation logic
  SubtitleFormatValidator.php Regex-based SRT/WebVTT structure validation
  Cli.php                     CLI argument parsing and report rendering
bin/srt-validator             Executable command-line entry point
build/build-phar.php          PHAR builder
examples/                     Local (gitignored) example fixtures + defect files
tests/                        PHPUnit test suite
.github/workflows/            CI + release workflow
```