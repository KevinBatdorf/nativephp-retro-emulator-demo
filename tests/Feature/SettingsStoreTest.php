<?php

use App\Support\Catalog;
use App\Support\SettingsStore;
use KevinBatdorf\RetroEmulator\Accuracy;

beforeEach(function () {
    $this->app->useStoragePath(sys_get_temp_dir().'/settings-store-test-'.uniqid());
});

afterEach(function () {
    $dir = storage_path('app/demo');
    foreach (glob($dir.'/*.json') ?: [] as $file) {
        unlink($file);
    }
});

it('neutralizes ares-only picture values when the engine is not ares', function () {
    SettingsStore::setGlobal('luminance', 80);
    SettingsStore::setGlobal('gamma', 150);
    SettingsStore::setGlobal('overscan', true);
    SettingsStore::setSystem('gb', 'backend', 'sameboy');

    $config = SettingsStore::configFor('gb')->toArray();

    expect($config['luminance'])->toBe(100)
        ->and($config['gamma'])->toBe(100)
        ->and($config['overscan'])->toBeFalse();
});

it('keeps non-neutral picture values on ares', function () {
    SettingsStore::setGlobal('luminance', 80);
    SettingsStore::setSystem('gb', 'backend', 'ares');

    expect(SettingsStore::configFor('gb')->toArray()['luminance'])->toBe(80);
});

it('neutralizes for a bench override backend too', function () {
    SettingsStore::setGlobal('saturation', 50);

    $config = SettingsStore::configFor('gb', ['backend' => 'sameboy'])->toArray();

    expect($config['saturation'])->toBe(100);
});

it('sends accuracy only for systems with a second renderer', function () {
    SettingsStore::setSystem('sfc', 'accurate', true);
    SettingsStore::setSystem('gba', 'accurate', true);
    SettingsStore::setSystem('gb', 'accurate', true);

    // toArray maps accuracy to the wire key pixelAccuracy.
    expect(SettingsStore::configFor('sfc')->toArray()['pixelAccuracy'])->toBeTrue()
        ->and(SettingsStore::configFor('gba')->toArray()['pixelAccuracy'])->toBeTrue()
        ->and(SettingsStore::configFor('gb')->toArray())->not->toHaveKey('pixelAccuracy');
});

it('falls back to the legacy global accurate key', function () {
    SettingsStore::setGlobal('accurate', true);

    expect(SettingsStore::accurateFor('sfc'))->toBeTrue();

    SettingsStore::setSystem('sfc', 'accurate', false);

    expect(SettingsStore::accurateFor('sfc'))->toBeFalse();
});

it('carries rewind and its buffer in the boot config', function () {
    SettingsStore::setGlobal('rewind', true);

    $config = SettingsStore::configFor('sfc')->toArray();

    expect($config['rewind'])->toBeTrue()
        ->and($config['rewindBufferSeconds'])->toBe(SettingsStore::REWIND_BUFFER_SECONDS);
});

it('omits the rewind buffer when rewind is off', function () {
    expect(SettingsStore::configFor('sfc')->toArray())->not->toHaveKey('rewindBufferSeconds');
});

it('resolves the effective backend through explicit choice, config map, then ares', function () {
    // The plugin's merged config prefers mGBA for gba out of the box.
    expect(SettingsStore::effectiveBackend('gba'))->toBe('mgba');

    SettingsStore::setSystem('gba', 'backend', 'ares');
    expect(SettingsStore::effectiveBackend('gba'))->toBe('ares');

    config(['retro-emulator.backends' => []]);
    SettingsStore::setSystem('gba', 'backend', '');
    expect(SettingsStore::effectiveBackend('gba'))->toBe('ares');
});

it('gives GBC and GBA toggle fallbacks alongside GB', function () {
    expect(Catalog::toggles('gbc'))->toHaveKeys(['colorEmulation', 'interframeBlending'])
        ->and(Catalog::toggles('gb'))->toHaveKeys(['colorEmulation', 'interframeBlending'])
        ->and(Catalog::toggles('gba'))->toHaveKeys(['colorEmulation', 'interframeBlending']);
});

it('annotates engine-restricted toggles in the fallback map', function () {
    // Mirrors the engines' capability declarations: DMG colour emulation is
    // a SameBoy boolean (ares models it as a palette); CGB has it on both.
    expect(Catalog::toggles('sfc')['deepBlackBoost']['note'])->toBe('ares engine only')
        ->and(Catalog::toggles('gb')['colorEmulation']['note'])->toBe('sameboy engine only')
        ->and(Catalog::toggles('gbc')['colorEmulation']['note'])->toBe('')
        ->and(Catalog::toggles('gba')['interframeBlending']['note'])->toBe('ares engine only');
});

it('builds GbConfig for gbc with both toggles applied', function () {
    SettingsStore::setSystem('gbc', 'colorEmulation', true);
    SettingsStore::setSystem('gbc', 'interframeBlending', true);
    SettingsStore::setSystem('gbc', 'backend', 'ares');

    $config = SettingsStore::configFor('gbc')->toArray();

    expect($config['colorEmulation'])->toBeTrue()
        ->and($config['interframeBlending'])->toBeTrue();
});
