# Retro Emulator — Demo & Conformance App

Demo and validation app for the
[`kevinbatdorf/retro-emulator`](../nativephp-retro-emulator) NativePHP Mobile
plugin. Two jobs in one APK:

- **Demo** — opens on a console grid; tap one and a bundled, freely-licensed
  homebrew game boots with touch controls (`<native:dpad>` + buttons), a
  settings overlay (picture, audio, CRT shader, rewind, accuracy), save
  states, fast-forward, and screenshots. `/dpads` is a bonus gallery of the
  d-pad element's styling knobs steering a ball via `SharedValue`s.
- **Conformance** — a step machine drives all 42 bridge functions against the
  live native layer: success shapes, documented error codes, native→PHP event
  delivery, and a reboot round trip that boots ares' accurate SNES PPU and
  reads the binding back. Results render on screen next to a running ROM.

## Fastest way to validate: install the APK

No toolchain needed — grab the debug APK (GitHub release, or build output at
`nativephp/android/app/build/outputs/apk/debug/app-debug.apk`):

```bash
adb install app-debug.apk
```

The app opens on the demo home screen. For the conformance suite, build with
`--start-url=/conformance` (see below) or navigate there; it runs by itself
and should show "ALL GREEN — N checks passed" within a minute or two. Full
results land in `storage/app/conformance-results.json`:

```bash
adb exec-out run-as <app-id> cat app_storage/persisted_data/storage/app/conformance-results.json
```

Everything bundled is freely-licensed homebrew, credited in-app (home →
Credits & licenses) and in `resources/roms/license.txt` — no copyrighted game
content anywhere. uCity's GPL source offer lives in both places.

## Build it yourself

Requirements: PHP 8.3+, Composer, Node, Android Studio (SDK; no NDK — the
plugin ships prebuilt native libs), and a device or AVD. NativePHP Mobile is
free for development.

```bash
composer install
cp .env.example .env && php artisan key:generate
npm install && npm run build
php artisan native:install
php artisan native:run android
```

> Until the plugin is published, `composer.json` resolves
> `kevinbatdorf/retro-emulator` from a sibling checkout via a Composer path
> repository — clone the plugin repo next to this one.

Useful `native:run` flags: a device id for non-interactive runs
(`php artisan native:run android <adb-id> --no-tty --no-interaction`), and
`--start-url=/conformance` to open on the suite instead of the demo
(the start URL is baked into the bundle — `pm clear` after switching it).

### Development loop

Native code changes require a rebuild (`native:run`). For PHP/Blade iteration
on the demo's screens, use
[Jump](https://nativephp.com/docs/mobile/3/the-basics/jump) after the first
build: `php artisan native:jump`, scan the QR, and edits hot-reload without
rebuilding.

## Screens

| Route | What it proves |
|---|---|
| `/home` | console grid from `Emulator::systems()`, one bundled game each |
| `/play/{id}` | the whole runtime surface: touch controls, settings overlay, states, rewind, accuracy toggle |
| `/dpads` | `<native:dpad>` styling knobs + `SharedValue` pan integration |
| `/credits` | bundled-game attribution (a license obligation, not decoration) |
| `/conformance` | the 96-step suite against the live native layer |
| `/systems`, `/probe`, `/errors`, `/audio`, `/shader`, `/declarative` | dev probes |

Swap in your own game without rebuilding: drop a ROM into
`storage/app/roms/<system>/` on device — on Android that path is
`app_storage/persisted_data/storage/app/roms/` — and it takes precedence over
the bundled pick (first filename in name order wins; there is no picker UI).

## What's here

- `app/Conformance/ConformanceRunner.php` — the step machine: every bridge
  function, event waits with timeouts, results as data. Pest-covered
  (`tests/Unit/ConformanceRunnerTest.php`) against a fake native layer
  implementing the documented bridge contract.
- `app/Native/PlayScreen.php` — the demo's runtime surface; settings persist
  via `app/Support/SettingsStore.php` and feed the plugin's typed configs.
- `app/Support/BundledRoms.php` — bundled-game table + the drop-in override.
- `scripts/fetch_test_roms.sh` — refreshes the bundled homebrew ROMs.

## Known device notes

- First boot extracts the app bundle (~90 s). Keep the screen awake
  (`adb shell svc power stayon usb`) — a sleep during extraction crashes the
  dev-element beta.
- `storage_path()` on device maps to `app_storage/persisted_data/storage/`
  (persistent), not `app_storage/laravel/storage/` (wiped by re-extraction on
  cold start).

## License

MIT for the demo's own code (see [`LICENSE`](LICENSE)); bundled games keep
their authors' licenses ([`resources/roms/license.txt`](resources/roms/license.txt)).
