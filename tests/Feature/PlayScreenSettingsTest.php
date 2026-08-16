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

it('flags a region change as boot-pending and clears it on revert', function () {
    $screen = playScreen()
        ->call('selectRegion', 'PAL')
        ->assertSee('Takes effect on reboot');

    $screen->call('selectRegion', '')
        ->assertDontSee('Takes effect on reboot');
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
