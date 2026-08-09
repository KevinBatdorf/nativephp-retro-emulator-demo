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
        ->call('cycleBackend')
        ->assertSee('Takes effect on reboot')
        ->assertSee('change applies on reboot');
});

it('clears the reboot note when the change is reverted', function () {
    // sfc cycles '' → ares → snes9x → '' — a full loop restores the store.
    playScreen()
        ->call('cycleBackend')
        ->call('cycleBackend')
        ->call('cycleBackend')
        ->assertDontSee('Takes effect on reboot')
        ->assertDontSee('applies on reboot');
});

it('flags a rewind change as boot-pending and clears it on revert', function () {
    $screen = playScreen()
        ->call('setRewind', true)
        ->assertSee('Takes effect on reboot');

    $screen->call('setRewind', false)
        ->assertDontSee('Takes effect on reboot');
});

it('suppresses the reboot bar before the first successful boot', function () {
    Native::test(PlayScreen::class, ['id' => 'sfc'], ['rom' => 'game.sfc'])
        ->set('menuOpen', true)
        ->call('setRewind', true)
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
