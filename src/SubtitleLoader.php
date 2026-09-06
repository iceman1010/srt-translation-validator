<?php

declare(strict_types=1);

namespace SrtValidator;

use Done\Subtitles\Subtitles;

/**
 * Loads subtitle files through mantas-done/subtitles, supporting both the
 * 1.x and 0.3.x API lines.
 *
 * 1.x is used natively (loadFromFile/loadFromString with content-based
 * format detection). 0.3.x instead has loadFile/loadString and requires an
 * explicit format, resolving it from the file extension when none is given;
 * the content is sniffed here so both versions behave alike for the two
 * formats this validator supports (WebVTT by its header, otherwise SRT).
 */
final class SubtitleLoader
{
    public static function loadFile(string $path): Subtitles
    {
        if (method_exists(Subtitles::class, 'loadFromFile')) {
            return Subtitles::loadFromFile($path);
        }

        // 0.3.x: pass the sniffed format explicitly so wrong or missing file
        // extensions still parse per their actual content (as 1.x does).
        return Subtitles::loadFile($path, self::detectFormat((string) @file_get_contents($path)));
    }

    public static function loadString(string $content): Subtitles
    {
        if (method_exists(Subtitles::class, 'loadFromString')) {
            return Subtitles::loadFromString($content);
        }

        return Subtitles::loadString($content, self::detectFormat($content));
    }

    /**
     * Mirrors 1.x format detection for the supported formats: a WEBVTT
     * header line means vtt, anything else is treated as srt. Byte-order
     * marks are tolerated (0.3.x strips them after this runs).
     */
    public static function detectFormat(string $content): string
    {
        $firstLine = strtok($content, "\r\n");

        return is_string($firstLine) && preg_match('/WEBVTT/', $firstLine) === 1 ? 'vtt' : 'srt';
    }
}
