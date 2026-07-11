# Retro Emulator — Demo & Conformance App

Validation app for the [`kevinbatdorf/retro-emulator`](../nativephp-retro-emulator) NativePHP
Mobile plugin. Open it and it proves the plugin works: a **conformance suite** drives every
one of the plugin's 35 bridge functions against the live native layer — asserting success
shapes, documented `NOT_IMPLEMENTED` errors, and native→PHP event delivery — and renders
pass/fail on screen next to a running homebrew game.

## Fastest way to validate: install the APK

No toolchain needed — grab the debug APK (GitHub release, or build output at
`nativephp/android/app/build/outputs/apk/debug/app-debug.apk`):

```bash
adb install app-debug.apk
```

Open the app. The conformance suite starts by itself; you should see the SNES homebrew ROM
running with a green "ALL GREEN — N checks passed" line under it within a minute. Full
results land in `storage/app/conformance-results.json` (readable via
`adb exec-out run-as <app-id> cat app_storage/persisted_data/storage/app/conformance-results.json`).

Test ROMs are freely-licensed homebrew (nestest, dmg-acid2, krom's SNES HelloWorld, SGDK
hello-world) bundled with the app — no copyrighted game content anywhere.

## Build it yourself

Requirements: PHP 8.3+, Composer, Node, Android Studio (SDK; no NDK — the plugin ships
prebuilt native libs), and a device or AVD. NativePHP Mobile is free for development.

```bash
composer install
cp .env.example .env && php artisan key:generate
npm install && npm run build
php artisan native:install
php artisan native:run android
```

> Until the plugin is published, `composer.json` resolves `kevinbatdorf/retro-emulator` from a
> sibling checkout via a Composer path repository — clone the plugin repo next to this one.

### Development loop

Native code changes require a rebuild (`native:run`). For PHP/Blade iteration on the demo's
screens, use [Jump](https://nativephp.com/docs/mobile/3/the-basics/jump) after the first
build: `php artisan native:jump`, scan the QR, and edits hot-reload without rebuilding.

## What's here

- `app/Conformance/ConformanceRunner.php` — the step machine: every bridge function, event
  waits with timeouts, results as data. Pest-covered (`tests/Unit/ConformanceRunnerTest.php`)
  against a fake native layer implementing the documented bridge contract.
- `app/Native/ConformanceScreen.php` + `resources/views/conformance.blade.php` — the screen:
  `<native:emulator />` surface, auto-run, `#[On]` event capture, results JSON.
- `scripts/fetch_test_roms.sh` — refreshes the bundled homebrew ROMs.

## Known device notes

- First boot extracts the app bundle (~90 s). Keep the screen awake
  (`adb shell svc power stayon usb`) — a sleep during extraction crashes the dev-element beta.
- `storage_path()` on device maps to `app_storage/persisted_data/storage/` (persistent), not
  `app_storage/laravel/storage/` (wiped by re-extraction on cold start).
