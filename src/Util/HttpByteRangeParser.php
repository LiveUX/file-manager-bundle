<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Util;

/**
 * RFC 9110 §14 compliant single byte-range parser and stream helper shared by
 * DownloadController and ShareController.
 */
final class HttpByteRangeParser
{
    /**
     * Parses an HTTP `Range` header against `$fileSize`.
     *
     * - Returns `['status' => 'ignore']` for non-`bytes` units or multi-range headers (e.g. PDF.js
     *   multi-range requests), signaling the controller to ignore `Range` and respond `200 OK`.
     * - Returns `['status' => 'invalid']` for unsatisfiable single `bytes=` ranges (`416`).
     * - Returns `['status' => 'ok', 'start' => int, 'end' => int]` for satisfiable single ranges (`206`).
     *
     * @return array{status: 'ok', start: int, end: int}|array{status: 'ignore'|'invalid'}
     */
    public static function parseSingleByteRange(string $header, int $fileSize): array
    {
        $trimmed = trim($header);
        if ($trimmed === '' || $fileSize <= 0) {
            return ['status' => 'ignore'];
        }

        if (preg_match('/^bytes=(.+)$/i', $trimmed, $unitMatch) !== 1) {
            return ['status' => 'ignore'];
        }

        $rangeSpec = trim($unitMatch[1]);
        if ($rangeSpec === '' || str_contains($rangeSpec, ',')) {
            // Multi-range requests (e.g. `bytes=0-1023, 2048-3071`) fall back to full 200 OK per RFC 9110.
            return ['status' => 'ignore'];
        }

        if (preg_match('/^(\d*)-(\d*)$/', $rangeSpec, $matches) !== 1) {
            return ['status' => 'invalid'];
        }

        $rawStart = $matches[1];
        $rawEnd = $matches[2];
        if ($rawStart === '' && $rawEnd === '') {
            return ['status' => 'invalid'];
        }

        if ($rawStart === '') {
            $suffix = (int) $rawEnd;
            if ($suffix <= 0) {
                return ['status' => 'invalid'];
            }
            $start = max(0, $fileSize - $suffix);
            $end = $fileSize - 1;
        } else {
            $start = (int) $rawStart;
            $end = $rawEnd !== '' ? min((int) $rawEnd, $fileSize - 1) : ($fileSize - 1);
        }

        if ($start < 0 || $start >= $fileSize || $end < $start) {
            return ['status' => 'invalid'];
        }

        return ['status' => 'ok', 'start' => $start, 'end' => $end];
    }

    /**
     * Streams `$length` bytes starting at `$start` from a Flysystem stream, falling back to
     * discard-reading when the underlying stream is non-seekable.
     *
     * @param resource $stream
     */
    public static function echoStreamRange($stream, int $start, int $length): void
    {
        if ($start > 0 && @fseek($stream, $start) !== 0) {
            $remainingSkip = $start;
            while ($remainingSkip > 0 && !feof($stream)) {
                $discard = fread($stream, min(65536, $remainingSkip));
                if ($discard === false || $discard === '') {
                    return;
                }
                $remainingSkip -= strlen($discard);
            }
        }

        $remaining = $length;
        while ($remaining > 0 && !feof($stream)) {
            $chunk = fread($stream, min(65536, $remaining));
            if ($chunk === false || $chunk === '') {
                break;
            }
            echo $chunk;
            $remaining -= strlen($chunk);
        }
    }
}
