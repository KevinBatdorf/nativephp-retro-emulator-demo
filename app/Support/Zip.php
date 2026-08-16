<?php

namespace App\Support;

/**
 * Minimal zip reader — the on-device PHP ships without ext-zip, so
 * ZipArchive does not exist there. Reads the central directory and inflates
 * stored (0) and deflated (8) entries; anything else is skipped.
 */
class Zip
{
    /**
     * Extract the largest entry beside the archive and return its path.
     * Null when nothing extractable was found.
     */
    public static function extractLargest(string $zipPath): ?string
    {
        $data = @file_get_contents($zipPath);
        if ($data === false) {
            return null;
        }

        $best = null;
        foreach (self::entries($data) as $entry) {
            if ($entry['size'] > ($best['size'] ?? -1)) {
                $best = $entry;
            }
        }
        if ($best === null) {
            return null;
        }

        $bytes = self::read($data, $best);
        if ($bytes === null) {
            return null;
        }

        $dest = dirname($zipPath).'/'.basename($best['name']);
        file_put_contents($dest, $bytes);

        return $dest;
    }

    /**
     * Central-directory walk. The EOCD record sits in the last 64 KB
     * (comment can pad it); entries carry name, method, sizes, and the
     * local-header offset.
     *
     * @return list<array{name: string, method: int, size: int, csize: int, offset: int}>
     */
    private static function entries(string $data): array
    {
        $tail = substr($data, -65557);
        $eocd = strrpos($tail, "PK\x05\x06");
        if ($eocd === false) {
            return [];
        }
        $eocd += strlen($data) - strlen($tail);

        $meta = unpack('vcount/Vsize/Voffset', substr($data, $eocd + 10, 10));
        $pos = $meta['offset'];
        $entries = [];

        for ($i = 0; $i < $meta['count']; $i++) {
            if (substr($data, $pos, 4) !== "PK\x01\x02") {
                break;
            }
            $h = unpack(
                'vmethod/Vcrc/Vcsize/Vsize/vnameLen/vextraLen/vcommentLen/Vlocal',
                substr($data, $pos + 10, 2).substr($data, $pos + 16, 12)
                .substr($data, $pos + 28, 6).substr($data, $pos + 42, 4),
            );
            $name = substr($data, $pos + 46, $h['nameLen']);
            if (! str_ends_with($name, '/')) {
                $entries[] = [
                    'name' => $name, 'method' => $h['method'],
                    'size' => $h['size'], 'csize' => $h['csize'], 'offset' => $h['local'],
                ];
            }
            $pos += 46 + $h['nameLen'] + $h['extraLen'] + $h['commentLen'];
        }

        return $entries;
    }

    /** @param array{name: string, method: int, size: int, csize: int, offset: int} $entry */
    private static function read(string $data, array $entry): ?string
    {
        if (substr($data, $entry['offset'], 4) !== "PK\x03\x04") {
            return null;
        }
        // Local-header name/extra lengths can differ from the central copy.
        $l = unpack('vnameLen/vextraLen', substr($data, $entry['offset'] + 26, 4));
        $start = $entry['offset'] + 30 + $l['nameLen'] + $l['extraLen'];
        $raw = substr($data, $start, $entry['csize']);

        return match ($entry['method']) {
            0 => $raw,
            8 => gzinflate($raw) ?: null,
            default => null,
        };
    }
}
