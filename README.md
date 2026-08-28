# SRT Translation Validator

A PHP tool that validates subtitle translations. It compares an **original**
subtitle file against its **translation** and reports four kinds of defects:

| Defect                | What it detects                                                          |
| --------------------- | ------------------------------------------------------------------------ |
| `invalid_format`      | Malformed SRT/WebVTT structure (bad or missing timestamp lines, garbage) |
| `missing_parts`       | Captions present in the original but absent in the translation           |
| `partial_translation` | Large caption blocks detected in the wrong language (e.g. left untranslated) |
| `timestamp_mismatch`  | Captions whose start/end times drifted beyond a tolerance                |

This project is used in two different ways, so this README is split in two:

| Who you are                          | Jump to                                          |
| ------------------------------------ | ------------------------------------------------ |
| You just want to check subtitle files from a terminal | [For command-line users](#for-command-line-users) |
| You want to call the validator from your own PHP code | [For PHP developers](#for-php-developers)         |

---

# For command-line users

You don't need to write any code. Download the pre-built `srt-translation-validator.phar`
(a single executable file), run it against the two subtitle files, and read the report.

## Requirements

- PHP 7.4+ on your machine (no Composer, no composer packages needed)

## Downloading

Get the PHAR from the **Releases** page of this project's GitHub repository
(each release is named `v1.0.0`, `v1.0.1`, ... and carries the PHAR as an
asset):

```bash
# download srt-translation-validator.phar from the latest release, then:
chmod +x srt-translation-validator.phar
sudo mv srt-translation-validator.phar /usr/local/bin/srt-translation-validator
```

After that, `srt-translation-validator` works as a normal command.

## Usage

```bash
srt-translation-validator <original-file> <translation-file> [options]
```

The CLI accepts exactly two positional arguments – the original subtitle file
(also called the reference) and the file being validated (the translation) –
compares them, and prints a human-readable report.

### Options

| Option                 | Description                                                              |
| ---------------------- | ------------------------------------------------------------------------ |
| `-l, --lang=CODE`      | Expected language of the translation (ISO 639-1, e.g. `de`). Default: auto-detected from the translation text. |
| `-t, --tolerance=SEC`  | Timestamp drift tolerance in seconds (default: `0.5`).                   |
| `-j, --json`           | Print the report as JSON (for scripts, agents and LLMs). Exit codes are unchanged. |
| `-h, --help`           | Show usage help.                                                         |
| `-V, --version`        | Print the version and exit.                                              |
| `--update[=version]`   | Update the tool to the latest release (or a specific version, e.g. `--update=1.0.1`). |

### Exit codes

| Code | Meaning                                                     |
| ---- | ----------------------------------------------------------- |
| `0`  | The translation is valid (no defects found).                 |
| `1`  | Defects were found – the translation is invalid.             |
| `2`  | Usage error, or the validator could not run.                 |

### Examples

```bash
# Compare and auto-detect the translation language
srt-translation-validator original.srt translation.de.srt

# Explicit expected language
srt-translation-validator original.srt translation.srt -l de

# Stricter timestamp tolerance (100 ms)
srt-translation-validator -t 0.1 -l de Movie.en.srt Movie.pt.srt

# Machine-readable output for scripts, agents and LLMs
srt-translation-validator original.srt translation.srt -l de --json
```

### JSON output

With `--json` the report is printed as a single JSON object on stdout (nothing
else is written, so it is safe to pipe into `jq` or parse directly). Errors are
reported as `{"error": "..."}` with exit code `2`.

```json
{
    "valid": false,
    "result": "failed",
    "original": "original.srt",
    "translation": "translation.srt",
    "language": "de",
    "timestamp_tolerance": 0.5,
    "defect_count": 2,
    "defects_by_type": {
        "missing_caption": 1,
        "missing_parts": 1
    },
    "defects": [
        {
            "type": "missing_parts",
            "message": "Translation has 24 captions, original has 25. Missing 1 captions."
        },
        {
            "type": "missing_caption",
            "message": "Caption #25 is missing in translation",
            "caption_number": 25,
            "original_text": "English movie line number 25."
        }
    ]
}
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

## Updating the tool

```bash
srt-translation-validator --update            # update to the latest release
srt-translation-validator --update=1.0.1      # update to a specific version
```

This fetches the release from GitHub, downloads the new PHAR, sanity-checks it
and atomically replaces the running file. If the file is not writable (e.g.
installed in `/usr/local/bin` as root), run it with `sudo`.

---

# For PHP developers

Call the validation logic directly from your own PHP code via Composer.

## Requirements

- PHP 7.4+ (8.x recommended)
- [Composer](https://getcomposer.org/)
- Extension `mbstring`

## Installation

```bash
composer require srt/translation-validator
```

This also publishes the `srt-validator` binary, so the command-line tool from
the previous section is available in `vendor/bin/` too.

## Full validation

Compare an original subtitle file against its translation, all in one call:

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

### Result structure

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

## Validation of file format only

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

## Development

Everything below is for people working on the *validator itself*.

### Building the PHAR

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
on any machine with PHP 7.4+. The GitHub repository used by `--update` is
auto-detected from the `origin` remote and embedded into the PHAR; override it
in `build/build-phar.php` if needed.

### Running the tests

```bash
composer test
# or
vendor/bin/phpunit tests/
```

The suite contains structure/format tests, library integration tests, and
end-to-end CLI tests. Most are self-contained; the tests that rely on the
large example subtitle files automatically skip when those local fixtures are
not checked out.

### Releasing a new version

The version lives in the `VERSION` file at the repository root (plain semver,
e.g. `1.0.0`). The release process:

1. Bump `VERSION` (patch for fixes, minor for features).
2. Commit the bump **together with** the change, and push to `main`.
3. A GitHub Actions workflow (`.github/workflows/build-phar.yml`) runs the
   tests on PHP 8.1/8.2/8.3 and, **only when `VERSION` changed** compared to
   the latest released tag, builds the PHAR, verifies it, and creates/updates
   the GitHub **Release** `v<VERSION>` with the PHAR attached.

If you push a change without bumping `VERSION`, only the tests run — no PHAR
is built and no release is touched. (Via "Run workflow" in the Actions tab you
can force a rebuild of the current version at any time.)

## Project layout

```
src/                          Library classes (PSR-4: SrtValidator\)
  SrtTranslationValidator.php Full translation validation logic
  SubtitleFormatValidator.php Regex-based SRT/WebVTT structure validation
  Cli.php                     CLI parsing, report rendering, --version/--update
bin/srt-validator             Executable command-line entry point
build/build-phar.php          PHAR builder (embeds VERSION + release repo)
VERSION                       Current release version (drives the GitHub release)
examples/                     Local (gitignored) example fixtures + defect files
tests/                        PHPUnit test suite
.github/workflows/            CI + release workflow
AGENTS.md                     Workflow rules for AI agents (version bumping)
```