<?php

namespace App\Native;

use App\Support\Catalog;
use App\Support\Library;
use Illuminate\View\View;
use KevinBatdorf\RetroEmulator\Facades\Emulator;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Edge\Transition;
use Native\Mobile\Facades\Dialog;

/**
 * Screen 2 — System screen / ROM picker for one system id (route param).
 *
 * The saved ROM folder is scanned for the system's extensions; matches plus the
 * bundled homebrew ROM make the list, each tapping through to Play. With no
 * folder picker in NativePHP Mobile (documented gap), the folder is a typed
 * path (default /data/local/tmp) the app scans directly. Systems that require a
 * BIOS (gba, ps1) also expose a BIOS-path field here — the user types the path
 * to their own BIOS file, which Play forwards into the config's biosPath.
 */
class SystemScreen extends NativeComponent
{
    public string $id = '';

    public string $name = '';

    public bool $biosRequired = false;

    public string $folder = '';

    public array $roms = [];

    public function navTitle(): string
    {
        return $this->name !== '' ? $this->name : 'System';
    }

    public function mount(): void
    {
        $this->id = (string) $this->param('id');

        $meta = collect(Emulator::systems())->firstWhere('id', $this->id) ?? [];
        $this->name = $meta['name'] ?? strtoupper($this->id);
        $this->biosRequired = (bool) ($meta['biosRequired'] ?? false);

        $this->folder = Library::folder($this->id);
        $this->rescan();
    }

    public function setFolder(string $path): void
    {
        if (trim($path) === '') {
            return;
        }

        Library::setFolder($this->id, $path);
        $this->folder = Library::folder($this->id);
        $this->rescan();
    }

    public function rescan(): void
    {
        $this->roms = Library::scan($this->id);
    }

    public function setBios(string $path): void
    {
        if (trim($path) === '') {
            return;
        }

        Library::setBios($this->id, $path);
        Dialog::toast('BIOS set: '.basename($path));
    }

    /** Hand the chosen ROM to the Play screen (path travels as nav data). */
    public function boot(string $rom): void
    {
        $this->navigate("/play/{$this->id}", ['rom' => $rom])->transition(Transition::None);
    }

    public function render(): View
    {
        return view('system', [
            'extensions' => Catalog::extensions($this->id),
            'bios' => Library::bios($this->id),
        ]);
    }
}
