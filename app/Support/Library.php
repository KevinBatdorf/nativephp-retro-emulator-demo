<?php

namespace App\Support;

/**
 * ROM discovery for a system. NativePHP Mobile exposes no folder-picker or
 * directory-chooser API (only a photo/video gallery picker), so — per the
 * plan's documented fallback — the folder is a typed path the app can read
 * (default /data/local/tmp, where `adb push`ed ROMs land) that we scan with
 * plain PHP. Bundled homebrew ROMs are always offered on top so the demo has
 * something to boot on a fresh device with nothing pushed.
 *
 * BIOS path is stored per system for the biosRequired systems — gba and ps1
 * need one (Emulator::systems() reports biosRequired). Same typed-path story
 * as the ROM folder: no file picker, so the user enters the BIOS file path.
 */
class Library
{
    private const FILE = 'library';

    public const DEFAULT_FOLDER = '/data/local/tmp';

    public static function folder(string $id): string
    {
        $data = JsonStore::read(self::FILE);

        return $data[$id]['folder'] ?? self::DEFAULT_FOLDER;
    }

    public static function setFolder(string $id, string $path): void
    {
        $data = JsonStore::read(self::FILE);
        $data[$id]['folder'] = trim($path);
        JsonStore::write(self::FILE, $data);
    }

    public static function clearFolder(string $id): void
    {
        $data = JsonStore::read(self::FILE);
        unset($data[$id]['folder']);
        JsonStore::write(self::FILE, $data);
    }

    public static function bios(string $id): ?string
    {
        $data = JsonStore::read(self::FILE);

        return $data[$id]['bios'] ?? null;
    }

    public static function setBios(string $id, string $path): void
    {
        $data = JsonStore::read(self::FILE);
        $data[$id]['bios'] = trim($path);
        JsonStore::write(self::FILE, $data);
    }

    /**
     * Every bootable ROM for a system: the bundled homebrew ROM (seeded into
     * storage on first use) plus everything in the saved folder that matches
     * the system's extensions.
     *
     * @return array<int, array{path: string, name: string, bundled: bool}>
     */
    public static function scan(string $id): array
    {
        $roms = [];

        $bundled = BundledRoms::forSystem($id);
        if ($bundled !== null) {
            $roms[$bundled] = ['path' => $bundled, 'name' => basename($bundled), 'bundled' => true];
        }

        $folder = self::folder($id);
        $extensions = Catalog::extensions($id);

        // Probe well-known ROM names directly (file read works even where
        // directory listing is denied, e.g. /data/local/tmp).
        foreach (Catalog::knownRoms($id) as $name) {
            $full = rtrim($folder, '/')."/{$name}";
            if (is_file($full) && is_readable($full)) {
                $roms[$full] = ['path' => $full, 'name' => $name, 'bundled' => false];
            }
        }

        // The app sandbox can't always list an arbitrary device path (e.g.
        // /data/local/tmp is readable by native ares but not listable by the
        // PHP process). Guard so an unreadable folder yields the bundled ROMs
        // rather than a fatal "Failed to open directory" warning.
        if ($extensions !== [] && is_dir($folder) && is_readable($folder)) {
            foreach (@scandir($folder) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $full = rtrim($folder, '/')."/{$entry}";

                if (! is_file($full)) {
                    continue;
                }

                $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));

                if (in_array($ext, $extensions, true)) {
                    $roms[$full] = ['path' => $full, 'name' => $entry, 'bundled' => false];
                }
            }
        }

        $roms = array_values($roms);
        usort($roms, fn ($a, $b) => [$b['bundled'], $a['name']] <=> [$a['bundled'], $b['name']]);

        return $roms;
    }
}
