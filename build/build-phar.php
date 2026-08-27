<?php

declare(strict_types=1);

/**
 * Builds a self-contained PHAR of the subtitle translation validator.
 *
 * Usage:
 *   php -d phar.readonly=0 build/build-phar.php
 *
 * The archive ships the application source (src/), the CLI entry point (bin/)
 * and the production vendor tree. Run "composer install --no-dev" first so the
 * archive does not pull in phpunit and other dev-only packages.
 */

$root = dirname(__DIR__);
$buildDir = $root . DIRECTORY_SEPARATOR . 'build';
$pharFile = $buildDir . DIRECTORY_SEPARATOR . 'srt-translation-validator.phar';
$alias = 'srt-translation-validator.phar';

if (!class_exists('Phar')) {
    fwrite(STDERR, "Error: the Phar extension is not available.\n");
    exit(1);
}

$readonly = in_array(ini_get('phar.readonly'), ['1', 'On', 'true'], true);
if ($readonly) {
    fwrite(STDERR, "Error: phar.readonly is enabled. Run with: php -d phar.readonly=0 " . basename(__FILE__) . "\n");
    exit(1);
}

if (!is_dir($buildDir)) {
    mkdir($buildDir, 0755, true);
}

if (!is_file($root . '/vendor/autoload.php')) {
    fwrite(STDERR, "Error: vendor/autoload.php not found. Run 'composer install --no-dev' first.\n");
    exit(1);
}

if (file_exists($pharFile)) {
    unlink($pharFile);
}

$phar = new Phar($pharFile, 0, $alias);
$phar->startBuffering();

$count = 0;
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if (!$file->isFile()) {
        continue;
    }
    $path = $file->getPathname();
    $relative = substr($path, strlen($root) + 1);
    $relative = str_replace('\\', '/', $relative);

    if (!preg_match('#^(src|bin|vendor)(?:/|$)#', $relative)) {
        continue;
    }
    // Skip hidden files/directories anywhere below the packaged roots.
    if (preg_match('#(?:^|/)\.#', $relative)) {
        continue;
    }

    $phar->addFile($path, $relative);
    $count++;
}

// The VERSION file is shipped at the archive root so `--version` works both
// from a checkout and from inside the PHAR (same path resolution).
if (is_file($root . '/VERSION')) {
    $phar->addFile($root . '/VERSION', 'VERSION');
    $count++;
}

// The GitHub repository this project is released from ("owner/repo"). Needed by
// the --update self-update feature. Auto-detected from the git remote, with a
// fallback for offline builds.
$releaseRepo = 'iceman1010/srt-translation-validator';
$gitUrl = @shell_exec('git -C ' . escapeshellarg($root) . ' remote get-url origin 2>/dev/null');
if (is_string($gitUrl) && trim($gitUrl) !== '') {
    $url = trim($gitUrl);
    if (strpos($url, 'git@') === 0) {
        $url = preg_replace('#^git@[^:]+:#', '', $url);
    } else {
        $url = preg_replace('#^https?://[^/]+/#', '', $url);
    }
    $url = preg_replace('#\.git$#', '', $url);
    $url = rtrim($url, '/');
    if (strpos($url, '/') !== false) {
        $releaseRepo = $url;
    }
}

$stub = <<<PHP
#!/usr/bin/env php
<?php
Phar::mapPhar('{$alias}');
define('SRT_RELEASE_REPO', '{$releaseRepo}');
require 'phar://{$alias}/bin/srt-validator';
__HALT_COMPILER();
PHP;
$phar->setStub($stub);
$phar->setSignatureAlgorithm(Phar::SHA256);

if (extension_loaded('zlib')) {
    $phar->compressFiles(Phar::GZ);
}

$phar->stopBuffering();

chmod($pharFile, 0755);

printf(
    "Built %s (%s files, %s bytes)\n",
    $pharFile,
    number_format($count),
    number_format(filesize($pharFile))
);
printf("Release repo: %s (used by --update)\n", $releaseRepo);