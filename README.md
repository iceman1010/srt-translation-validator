# SRT Translation Validator

A PHP tool that validates subtitle translations. It compares an **original**
subtitle file against its **translation**, aligns the two caption lists on the
timeline and reports these defect types:

| Defect                | Severity | What it detects                                                          |
| --------------------- | -------- | ------------------------------------------------------------------------ |
| `invalid_format`      | error    | Malformed SRT/WebVTT structure (bad or missing timestamp lines, garbage, NUL bytes) |
| `missing_caption`     | error    | Source captions with no translation counterpart at all (real content loss; music/annotation-only cues are excluded) |
| `partial_translation` | warning  | Large caption blocks detected in the wrong language. **Advisory only** - the detector label is decoration and never fails the verdict |
| `timestamp_mismatch`  | error    | Aligned captions whose start/end times drifted beyond a tolerance       |
| `untranslated_copy`   | error    | Nearly all captions are verbatim copies of the source - the model returned the original untranslated. Skipped when the source is already in the target language (same-language passthrough) |
| `edited_copy`         | error    | Nearly all captions are identical or >=90% similar to the source, yet not exactly identical - the model returned the original with light cosmetic edits. A different failure mode than `untranslated_copy` |
| `unexpected_script`   | error    | Translation letters in a script foreign to the target language (character hallucination, e.g. Cyrillic in Hungarian); letters already spelled that way in the source are exempt |
| `merged_captions`     | warning  | Source captions merged into a neighbouring translation caption (re-segmentation) |
| `split_captions`      | warning  | One source caption split across multiple translation captions           |
| `extra_caption`       | warning  | Translation captions with no counterpart in the original                |
| `format_mismatch`     | warning  | Source and translation use different container formats (compared on cue structure anyway) |
| `source_parse_failed` | warning  | The source file itself is malformed; the translation verdict is skipped  |
| `same_language_passthrough` | warning | The source is already written in the target language (declared with `--source-lang`, or evident from its script); the verbatim/untranslated gates are skipped |

## The verdict: "usable", not "identical"

Translation engines like DeepL routinely **re-segment** subtitles: they merge
2-3 short source captions into one translation caption (or split long ones),
which changes the caption count without losing any content. An index-by-index
comparison misreads that as hundreds of timestamp mismatches and missing
captions. This validator instead **aligns captions on the timeline** first,
then judges the translation by how much is actually wrong:

| Ratio                | Measures                                             | Default limit |
| -------------------- | ---------------------------------------------------- | ------------- |
| `content_loss`       | source captions with no translation counterpart (content captions only - music/annotation cues excluded) | 1%            |
| `timestamp_drift`    | aligned captions drifting beyond the tolerance       | 2%            |
| `partial_translation`| translation characters detected in the wrong language (base-code comparison: `es-mx` target matches `es` detection) | advisory (no limit) |
| `merged`             | source captions merged into neighbouring captions (content captions only) | 10%           |
| `verbatim_copy`      | aligned captions identical to the source             | 50% (advisory during a same-language passthrough) |
| `near_verbatim_copy` | aligned captions identical or >= 90% similar to the source (lightly edited passthrough) | 50% (advisory during a same-language passthrough) |
| `unexpected_script`  | translation letters in a foreign script (not counting letters inherited from the source) | 0% (zero tolerance) |
| `unaligned`          | source captions with no aligned translation pair     | advisory (no limit) |

- A translation is **usable (`valid: true`, exit 0)** when no ratio exceeds
  its limit. Merges and splits are always reported as warnings and never fail
  on their own.
- `--strict` fails on any error-severity defect, ignoring the limits.
- The measured ratios are always included in the report (`quality` block), so
  thresholds can be calibrated from real data. Each limit can be overridden
  per run with `--max-loss-ratio`, `--max-drift-ratio`,
  `--max-merge-ratio`, `--max-verbatim-ratio` and
  `--max-script-ratio` (values between `0` and `1`); `--max-errors` fails a
  run with more than N error-severity defects regardless of the ratios.
- **Same-language passthrough**: when the source file is already written in
  the target language, a verbatim copy is expected output, not a failure.
  Declare the source language with `--source-lang` (e.g. `--source-lang=bg`
  with `-l bg`); for non-Latin targets the validator also infers this from
  the source script automatically (Cyrillic source, `bg` target). A
  `same_language_passthrough` warning is emitted and the verbatim gates are
  skipped.
- **Source vs. model**: the translation is the artefact under review, so
  format failures in the translation fail the job. A malformed *source* is
  the user's input, not the model's failure: it is reported as a
  `source_parse_failed` warning and the verdict is skipped, never failed.
- Reading speed (cps) and line length are measured on the translation and
  reported in the `quality.readability` block (avg/max cps, longest line) as
  pure statistics - they never produce defects or affect the verdict.

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

### Installing via Composer

The tool is also on
[Packagist](https://packagist.org/packages/srt/translation-validator), so you can
install it with Composer and get it as a command-line tool without downloading
the PHAR:

```bash
composer global require srt/translation-validator
```

Add Composer's global `bin` directory to your `PATH` (e.g.
`~/.config/composer/vendor/bin` on Linux, `~/.composer/vendor/bin` on macOS),
then run it as `srt-validator`. Or require it in a project for its PHP API and
use `vendor/bin/srt-validator`. Composer installs from the source via git tags;
the PHAR from the Releases page is the always-current compiled build.

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
| `--source-lang=CODE`   | Declared language of the original file (e.g. from the job request). When it matches `--lang`, the verbatim/untranslated gates are skipped (same-language passthrough). For non-Latin targets this is also inferred from the source script automatically. |
| `-t, --tolerance=SEC`  | Timestamp drift tolerance in seconds (default: `0.5`).                   |
| `-j, --json`           | Print the report as JSON (for scripts, agents and LLMs). Exit codes are unchanged. |
| `--strict`             | Fail on any error-severity defect, ignoring the quality-ratio limits.    |
| `--max-loss-ratio=F`   | Max share of source captions with no translation at all (default: `0.01`). |
| `--max-drift-ratio=F`  | Max share of aligned captions with timestamp drift beyond the tolerance (default: `0.02`). |
| `--max-merge-ratio=F`  | Max share of source captions merged into neighbouring captions (default: `0.10`). |
| `--max-verbatim-ratio=F` | Max share of aligned captions that may be verbatim copies of the source (default: `0.50`). |
| `--max-near-verbatim-ratio=F` | Max share of aligned captions that may be identical or >=90% similar to the source; catches copies with light cosmetic edits as `edited_copy` (default: `0.50`). |
| `--max-script-ratio=F` | Max share of translation letters in a script foreign to the target language, not counting letters inherited from the source (default: `0`, zero tolerance). |
| `--max-errors=N`       | Fail when more than N error-severity defects are found, regardless of the ratios (default: no limit). |
| `--readability`        | Readability audit of a single subtitle file: runs a per-caption check against readability limits and lists every caption that exceeds them. Purely advisory - no verdict, no defects, exit code is 0 when every caption is clean and 1 when problems are found. Takes exactly one file. |
| `--max-cps=F`          | Max characters per second for the `--readability` audit (default: `20.0`). |
| `--max-cpl=N`          | Max characters per line for the `--readability` audit (default: `42`). |
| `--max-lines=N`        | Max lines per caption for the `--readability` audit (default: `2`). |
| `--limit=N`            | Cap the `--readability` listing to the first N problematic captions. File-wide stats and `problems_by_type` still cover every caption (default: show all). |
| `--worst-first`        | In the `--readability` listing, order problems for triage: critical before minor, then fastest reading speed first. |
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
            "merged": 0.0052,
            "verbatim_copy": 0.0026,
            "near_verbatim_copy": 0.0065,
            "unexpected_script": 0.0,
            "unaligned": 0.0052
        },
        "thresholds": {
            "content_loss": 0.01,
            "timestamp_drift": 0.02,
            "partial_translation": null,
            "merged": 0.1,
            "verbatim_copy": 0.5,
            "near_verbatim_copy": 0.5,
            "unexpected_script": 0.0,
            "unaligned": null
        },
        "readability": {
            "avg_cps": 12.4,
            "max_cps": 21.7,
            "max_cps_caption": 183,
            "max_cpl": 42,
            "max_cpl_caption": 402
        },
        "strict": false,
        "reasons": []
    }
}
```

`valid: false` reports carry non-empty `quality.reasons` naming every exceeded
limit (e.g. `"verbatim copy 70.52% exceeds the threshold 50.00%"`). Ratios
with a `null` threshold (`partial_translation`, `unaligned`) are advisory:
they are measured and reported but never flip the verdict.

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
  partial translation:   0.0%  (advisory)
  merged:               0.5%  (max 10.0%)
  verbatim copy:        0.3%  (max 50.0%)
  near verbatim copy:   0.7%  (max 50.0%)
  unexpected script:    0.0%  (max 0.0%)
  unaligned:            0.5%  (advisory)
  reading speed:      12.4 cps avg, 21.7 cps max (caption #183)
  line length:        42 chars max (caption #402)

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

## Readability audit

Pass `--readability` with **exactly one** subtitle file to list every caption
that is hard to read. It is a separate, advisory mode: no verdict, no defects,
no language detection - just the captions that exceed the readability limits,
with their exact values against the limits.

```bash
srt-translation-validator Movie.de.srt --readability
srt-translation-validator Movie.de.srt --readability --json   # for scripts/LLMs
srt-translation-validator Movie.de.srt --readability --max-cps 15 --max-cpl 37
srt-translation-validator Movie.de.srt --readability --limit 50 --worst-first
```

Limits (ISO-style subtitle guidelines): `--max-cps` characters per second
(default `20`), `--max-cpl` characters per line (default `42`), `--max-lines`
(default `2`).

Every problem carries a severity: **`critical`** when a value exceeds *twice*
its limit, otherwise **`minor`**. A caption is `critical` when any of its
issues is critical. `--limit N` caps the listing (stats still cover every
caption); `--worst-first` orders the listing for triage - critical first,
then fastest reading speed.

Exit codes: `0` every caption is clean, `1` at least one caption exceeds a
limit, `2` usage error or the file could not be read/parsed.

Captions shorter than `0.2s` are too brief to measure reading speed reliably:
they count towards `captions` but are excluded from the cps stats and
`avg_cps` (see `analyzed` in the JSON).

Human-readable output:

```
==================================================================
  Readability Audit
==================================================================

  File:               Movie.de.srt
  Captions:           1834
  Avg reading speed:  12.4 cps
  Max reading speed:  48.6 cps (caption #1485)
  Limits:             20 cps, 42 chars/line, 2 lines
  Problematic captions: 259

  Problematic captions (259)
------------------------------------------------------------------

  1. caption #1485  [critical]  [01:37:06,520 --> 01:37:07,755]  (1.2s, 60 chars)
     - critical - reading speed 48.6 cps exceeds the 20.0 cps limit
     | ICH GLAUBE, WENN DU ES WIRKLICH
     | ERNST MEINST, IHN ZU RETTEN,

...
```

JSON output omits nothing: each problem carries `caption`, `severity`,
`start_seconds`, `end_seconds`, `duration_seconds`, `chars`, `cps`, the full
`text` and an `issues` array (`type`, `value`, `limit`, `severity`), plus a
`problems_by_type` summary and the `thresholds` used. With `--limit`, `shown`
holds the capped count and `truncated` is `true` when problems were dropped;
`problems_by_type` and the stats always describe every caption.

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

// Override any quality threshold
// (defaults: loss 0.01 / drift 0.02 / merge 0.10 /
//  verbatim 0.50 / near-verbatim 0.50 / script 0.00;
//  partial_translation is advisory)
$validator->setMaxLossRatio(0.02);
$validator->setMaxDriftRatio(0.05);
$validator->setMaxMergeRatio(0.20);
$validator->setMaxVerbatimRatio(0.60);
$validator->setMaxNearVerbatimRatio(0.60);
$validator->setMaxScriptRatio(0.001);

// Declare the source language (e.g. from the job request): when it
// matches the target, the verbatim/untranslated gates are skipped
// (same-language passthrough). For non-Latin targets this is also
// inferred from the source script automatically.
$validator->setSourceLanguage('en');

// Fail when more than N error-severity defects are found,
// regardless of the ratios
$validator->setMaxErrors(10);

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
            'severity' => 'warning', // advisory: never fails the verdict
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
        [
            'type'     => 'unexpected_script',
            'severity' => 'error',
            'message'  => '42 of 18355 letters (0.23%) are written in a script that does not belong to language hu: cyrillic (42). Example characters: д л о',
            'foreign_chars' => 42,
            'total_letters' => 18355,
            'scripts'      => ['cyrillic' => 42],
            'examples'     => 'д л о',
            'language'     => 'hu',
            'ratio'        => 0.0023,
        ],
    ],
    'quality' => [
        'source_captions' => 1834,
        'aligned_pairs'   => 1808,
        'ratios' => [
            'content_loss'        => 0.0149,   // vs thresholds below (content captions only)
            'timestamp_drift'     => 0.0,
            'partial_translation' => 0.0,      // advisory, no threshold
            'merged'              => 0.006,
            'verbatim_copy'       => 0.0026,
            'near_verbatim_copy'  => 0.0065,
            'unexpected_script'   => 0.0,
            'unaligned'           => 0.0142,   // advisory, no threshold
        ],
        'thresholds' => [
            'content_loss'        => 0.01,
            'timestamp_drift'     => 0.02,
            'partial_translation' => null,
            'merged'              => 0.10,
            'verbatim_copy'       => 0.50,
            'near_verbatim_copy'  => 0.50,
            'unexpected_script'   => 0.0,
            'unaligned'           => null,
        ],
        // Informational reading statistics - never affect the verdict
        'readability' => [
            'avg_cps'         => 12.4,
            'max_cps'         => 21.7,
            'max_cps_caption' => 183,
            'max_cpl'         => 42,
            'max_cpl_caption' => 402,
        ],
        'max_errors' => null,
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
  CaptionAligner.php          Aligns source/translation captions on the timeline
  ScriptChecker.php           Foreign-script ("character hallucination") detection
  ReadabilityChecker.php      Per-caption readability audit (--readability)
  Cli.php                     CLI parsing, report rendering, --version/--update
bin/srt-validator             Executable command-line entry point
build/build-phar.php          PHAR builder (embeds VERSION + release repo)
VERSION                       Current release version (drives the GitHub release)
examples/                     Local (gitignored) example fixtures + defect files
tests/                        PHPUnit test suite
.github/workflows/            CI + release workflow
AGENTS.md                     Workflow rules for AI agents (git/VERSION are user-owned)
```