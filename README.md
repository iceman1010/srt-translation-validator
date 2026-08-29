# SRT Translation Validator

A PHP tool that validates subtitle translations. It compares an **original**
subtitle file against its **translation**, aligns the two caption lists on the
timeline and reports these defect types:

| Defect                | Severity | What it detects                                                          |
| --------------------- | -------- | ------------------------------------------------------------------------ |
| `invalid_format`      | error    | Malformed SRT/WebVTT structure (bad or missing timestamp lines, garbage) |
| `missing_caption`     | error    | Source captions with no translation counterpart at all (real content loss) |
| `partial_translation` | error    | Large caption blocks detected in the wrong language (e.g. left untranslated) |
| `timestamp_mismatch`  | error    | Aligned captions whose start/end times drifted beyond a tolerance       |
| `merged_captions`     | warning  | Source captions merged into a neighbouring translation caption (re-segmentation) |
| `split_captions`      | warning  | One source caption split across multiple translation captions           |
| `extra_caption`       | warning  | Translation captions with no counterpart in the original                |

## The verdict: "usable", not "identical"

Translation engines like DeepL routinely **re-segment** subtitles: they merge
2-3 short source captions into one translation caption (or split long ones),
which changes the caption count without losing any content. An index-by-index
comparison misreads that as hundreds of timestamp mismatches and missing
captions. This validator instead **aligns captions on the timeline** first,
then judges the translation by how much is actually wrong:

| Ratio                | Measures                                             | Default limit |
| -------------------- | ---------------------------------------------------- | ------------- |
| `content_loss`       | source captions with no translation counterpart      | 1%            |
| `timestamp_drift`    | aligned captions drifting beyond the tolerance       | 2%            |
| `partial_translation`| translation characters detected in the wrong language | 5%            |
| `merged`             | source captions merged into neighbouring captions    | 10%           |

- A translation is **usable (`valid: true`, exit 0)** when no ratio exceeds
  its limit. Merges and splits are always reported as warnings and never fail
  on their own.
- `--strict` fails on any error-severity defect, ignoring the limits.
- The measured ratios are always included in the report (`quality` block), so
  thresholds can be calibrated from real data. Each limit can be overridden
  per run with `--max-loss-ratio`, `--max-drift-ratio`,
  `--max-partial-ratio` and `--max-merge-ratio` (values between `0` and `1`).

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
| `--strict`             | Fail on any error-severity defect, ignoring the quality-ratio limits.    |
| `--max-loss-ratio=F`   | Max share of source captions with no translation at all (default: `0.01`). |
| `--max-drift-ratio=F`  | Max share of aligned captions with timestamp drift beyond the tolerance (default: `0.02`). |
| `--max-partial-ratio=F`| Max share of translation chars in the wrong language (default: `0.05`).  |
| `--max-merge-ratio=F`  | Max share of source captions merged into neighbouring captions (default: `0.10`). |
| `-h, --help`           | Show usage help.                                                         |
| `-V, --version`        | Print the version and exit.                                              |
| `--update[=version]`   | Update the tool to the latest release (or a specific version, e.g. `--update=1.0.1`). |

### Exit codes

| Code | Meaning                                                     |
| ---- | ----------------------------------------------------------- |
| `0`  | The translation is usable (no quality limit exceeded).       |
| `1`  | The translation is not usable (a limit was exceeded, or errors in `--strict` mode). |
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
    "valid": true,
    "result": "passed",
    "original": "original.srt",
    "translation": "translation.srt",
    "language": "de",
    "timestamp_tolerance": 0.5,
    "defect_count": 2,
    "error_count": 0,
    "warning_count": 2,
    "defects_by_type": {
        "merged_captions": 2
    },
    "defects": [
        {
            "type": "merged_captions",
            "severity": "warning",
            "message": "Original captions #316-317 are merged into translation caption #316",
            "source_start_caption": 316,
            "source_end_caption": 317,
            "translation_caption": 316,
            "caption_count": 2
        }
    ],
    "quality": {
        "source_captions": 765,
        "aligned_pairs": 761,
        "partial_chars_analyzed": 18220,
        "ratios": {
            "content_loss": 0.0,
            "timestamp_drift": 0.0,
            "partial_translation": 0.0,
            "merged": 0.0052
        },
        "thresholds": {
            "content_loss": 0.01,
            "timestamp_drift": 0.02,
            "partial_translation": 0.05,
            "merged": 0.1
        },
        "strict": false,
        "reasons": []
    }
}
```

`valid: false` reports carry non-empty `quality.reasons` naming every exceeded
limit (e.g. `"partial translation 45.35% exceeds the threshold 5.00%"`).

### Example report

```
==================================================================
  Subtitle Translation Validator
==================================================================

  Original:           original.srt
  Translation:        translation.nl.srt
  Expected language:  nl
  Timestamp tolerance: 0.5s

  Quality
------------------------------------------------------------------
  content loss:         0.0%  (max 1.0%)
  timestamp drift:      0.0%  (max 2.0%)
  partial translation:   0.0%  (max 5.0%)
  merged:               0.5%  (max 10.0%)

  Defects (4)
------------------------------------------------------------------

  1. MERGED CAPTIONS [warning]
Original captions #316 are merged into translation caption #316

  ...

==================================================================
  RESULT: PASSED - translation is usable (0 error(s), 4 warning(s) within limits)
==================================================================
```

A failed validation prints `RESULT: FAILED`, the error/warning counts and one
line per exceeded quality limit.

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

// Fail on any error-severity defect instead of judging by ratios
$validator->setStrict(true);

// Override any quality threshold (defaults: 0.01 / 0.02 / 0.05 / 0.10)
$validator->setMaxLossRatio(0.02);
$validator->setMaxDriftRatio(0.05);
$validator->setMaxPartialRatio(0.10);
$validator->setMaxMergeRatio(0.20);

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

`validate()` returns a verdict plus every defect, each tagged with a severity:

```php
[
    'valid'   => false,          // "usable": no quality ratio exceeded
    'error_count'   => 26,
    'warning_count' => 11,
    'defects' => [
        [
            'type'     => 'missing_caption',
            'severity' => 'error',
            'message'  => 'Caption #1178 is missing in translation',
            'caption_number' => 1178,
            'original_text' => 'We need to go now.',
        ],
        [
            'type'     => 'merged_captions',
            'severity' => 'warning',
            'message'  => 'Original captions #316-317 are merged into translation caption #316',
            'source_start_caption' => 316,
            'source_end_caption'   => 317,
            'translation_caption' => 316,
            'caption_count' => 2,
        ],
        [
            'type'     => 'partial_translation',
            'severity' => 'error',
            'message'  => 'Large block (captions 901-1400) detected as en instead of de - ...',
            'start_caption' => 901,
            'end_caption'   => 1400,
            'detected_language' => 'en',
            'confidence'    => 0.88,
        ],
        [
            'type'     => 'timestamp_mismatch',
            'severity' => 'error',
            'caption_number' => 301,
            'message'  => 'Caption #301 has timestamp drift',
            'original_start'     => 1334.627,
            'translation_start'  => 1336.627,
            'start_diff' => 2.0,
        ],
        [
            'type'     => 'invalid_format',
            'severity' => 'error',
            'line'     => 2112,
            'subtype'  => 'missing_timestamp',
            'message'  => 'Line 2112: expected a timing line (...) ...',
        ],
    ],
    'quality' => [
        'source_captions' => 1834,
        'aligned_pairs'   => 1808,
        'ratios' => [
            'content_loss'        => 0.0136,   // vs thresholds below
            'timestamp_drift'     => 0.0,
            'partial_translation' => 0.0,
            'merged'              => 0.006,
        ],
        'thresholds' => [
            'content_loss'        => 0.01,
            'timestamp_drift'     => 0.02,
            'partial_translation' => 0.05,
            'merged'              => 0.10,
        ],
        'strict'  => false,
        'reasons' => ['content loss 1.36% exceeds the threshold 1.00%'],
    ],
]
```

The captions are aligned on the timeline before comparison (see
`src/CaptionAligner.php`), so re-segmentation by the translation engine is
reported as `merged_captions`/`split_captions` warnings instead of cascades
of false mismatches.

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