<?php

use App\Native\PlayScreen;
use App\Support\SettingsStore;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    $this->app->useStoragePath(sys_get_temp_dir().'/play-settings-test-'.uniqid());
});

function playScreen(string $id = 'sfc')
{
    $component = Native::test(PlayScreen::class, ['id' => $id], ['rom' => 'game.sfc']);

    // Simulate a completed boot: the diff engine is inert until a snapshot
    // exists (menu opened mid-load must not show a reboot bar).
    $booted = json_decode(json_encode(SettingsStore::configFor($id)->toArray()), true);

    return $component
        ->set('booted', true)
        ->set('bootedConfig', $booted)
        ->set('menuOpen', true);
}

it('shows the reboot note and pinned bar when the engine changes', function () {
    playScreen()
        ->call('selectBackend', 'ares')
        ->assertSee('Takes effect on reboot')
        ->assertSee('change applies on reboot');
});

it('clears the reboot note when the change is reverted', function () {
    // snes9x is sfc's app default, so re-picking it stores '' and the wire
    // config matches a default boot again.
    playScreen()
        ->call('selectBackend', 'ares')
        ->call('selectBackend', 'snes9x')
        ->assertDontSee('Takes effect on reboot')
        ->assertDontSee('applies on reboot');

    expect(SettingsStore::system('sfc')['backend'])->toBe('');
});

it('applies rewind live without a reboot note when the bridge accepts it', function () {
    Native::fakeBridge();

    playScreen()
        ->call('setRewind', true)
        ->assertSet('rewindEnabled', true)
        ->assertDontSee('Takes effect on reboot')
        ->assertDontSee('applies on reboot');

    expect(SettingsStore::global()['rewind'])->toBeTrue();
});

it('does not persist a live setting when the bridge call fails', function () {
    Native::fakeBridge()->respondTo('Emulator.Configure', [
        'status' => 'error',
        'code' => 'INVALID_PARAMETERS',
        'message' => 'No core is running',
    ]);

    // A refused call must leave the toggle and the store untouched rather
    // than poisoning the next boot.
    playScreen()
        ->call('setRewind', true)
        ->assertSet('rewindEnabled', false)
        ->assertDontSee('Takes effect on reboot');

    expect(SettingsStore::global()['rewind'])->toBeFalse();
});

it('suppresses the reboot bar before the first successful boot', function () {
    Native::test(PlayScreen::class, ['id' => 'sfc'], ['rom' => 'game.sfc'])
        ->set('menuOpen', true)
        ->call('setAccurate', true)
        ->assertDontSee('applies on reboot');
});

it('reports accuracy changes under the wire key only for dual-renderer systems', function () {
    playScreen()
        ->call('setAccurate', true)
        ->assertSee('Takes effect on reboot');

    playScreen('gb')
        ->call('setAccurate', true)
        ->assertDontSee('Takes effect on reboot');
});

it('applies a system toggle live and persists it', function () {
    Native::fakeBridge();

    playScreen('gbc')
        ->call('setToggle', 'colorEmulation', true)
        ->assertDontSee('Takes effect on reboot');

    expect(SettingsStore::system('gbc')['colorEmulation'])->toBeTrue();
});

it('offers shipped bring-your-own cores alongside the bridge claimants', function () {
    // The bridge lists claimants only; a BYO core claims nothing until booted.
    Native::fakeBridge()->respondTo('Emulator.GetSystems', ['systems' => [[
        'id' => 'sfc', 'name' => 'SNES / Super Famicom',
        'supported' => true, 'stable' => true,
        'backends' => ['ares'],
        'capabilities' => ['ares' => ['videoSettings' => true, 'toggles' => ['deepBlackBoost'], 'bootOptions' => ['pixelAccuracy']]],
    ]]]);

    // set('menuOpen') skips refreshEngineData; only a real toggle cycle runs it.
    playScreen()
        ->call('toggleMenu')
        ->call('toggleMenu')
        ->assertSet('backendOptions', ['ares', 'snes9x'])
        ->call('selectBackend', 'snes9x');

    // snes9x resolves as the default, so picking it stores ''.
    expect(SettingsStore::system('sfc')['backend'])->toBe('');
});

it('refuses an engine-gated toggle locally instead of surfacing the native error', function () {
    Native::fakeBridge()->respondTo('Emulator.GetSystems', ['systems' => [[
        'id' => 'gb', 'name' => 'Game Boy',
        'supported' => true, 'stable' => true,
        'backends' => ['ares', 'sameboy'],
        'capabilities' => [
            'ares' => ['toggles' => ['interframeBlending'], 'bootOptions' => []],
            'sameboy' => ['toggles' => ['colorEmulation'], 'bootOptions' => ['rawAudio']],
        ],
    ]]]);

    // Booted on ares, which does not serve colorEmulation — SameBoy does.
    playScreen('gb')
        ->set('bootedBackend', 'ares')
        ->call('toggleMenu')
        ->call('toggleMenu')
        ->call('setToggle', 'colorEmulation', true);

    expect(SettingsStore::system('gb'))->not->toHaveKey('colorEmulation');
});

it('shows the engine the boot landed on, not the config wish', function () {
    // sfc's config prefers snes9x; the core reports it landed on ares.
    Native::fakeBridge()->respondTo('Emulator.GetStatus', [
        'status' => 'running', 'backend' => 'ares',
    ]);

    Native::test(PlayScreen::class, ['id' => 'sfc'], ['rom' => 'game.sfc'])
        ->call('pump')
        ->assertSet('booted', true)
        ->assertSet('bootedBackend', 'ares');
});

it('refuses an engine-gated boot option locally', function () {
    Native::fakeBridge()->respondTo('Emulator.GetSystems', ['systems' => [[
        'id' => 'gba', 'name' => 'Game Boy Advance',
        'supported' => true, 'stable' => true,
        'backends' => ['ares', 'mgba'],
        'capabilities' => [
            'ares' => ['toggles' => [], 'bootOptions' => ['pixelAccuracy']],
            'mgba' => ['toggles' => [], 'bootOptions' => []],
        ],
    ]]]);

    playScreen('gba')
        ->set('bootedBackend', 'mgba')
        ->call('toggleMenu')
        ->call('toggleMenu')
        ->call('setBootOption', 'pixelAccuracy', true);

    expect(SettingsStore::system('gba'))->not->toHaveKey('accurate');
});

it('rolls save states across three timestamped slots, newest first', function () {
    Native::fakeBridge();

    $screen = playScreen();
    foreach (range(1, 4) as $i) {
        $screen->call('saveStateNow');
    }

    $saves = $screen->get('saves');
    expect($saves)->toHaveCount(3);
    expect(array_column($saves, 'slot'))->toBe(['a', 'c', 'b']);
    expect($saves[0]['at'])->toBeGreaterThanOrEqual($saves[2]['at']);
});

it('reverts a toggle when the engine refuses it', function () {
    Native::fakeBridge()->respondTo('Emulator.SetSystemOptions', [
        'status' => 'error',
        'code' => 'UNSUPPORTED_OPTION',
        'message' => "Backend 'sameboy' does not support interframeBlending",
    ]);

    playScreen('gbc')
        ->call('setToggle', 'interframeBlending', true)
        ->assertDontSee('Takes effect on reboot');

    expect(SettingsStore::system('gbc'))->not->toHaveKey('interframeBlending');
});

it('reset pushes defaults live and leaves no reboot residue for live keys', function () {
    Native::fakeBridge();

    $screen = playScreen();
    $screen->call('updatedLuminance', 60);
    expect(SettingsStore::global()['luminance'])->toBe(60);

    $screen->call('resetSettings')
        ->assertDontSee('applies on reboot');

    expect(SettingsStore::global()['luminance'])->toBe(100);
});
