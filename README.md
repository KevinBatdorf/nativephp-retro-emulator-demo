# Retro Emulator — Demo

The showcase app for the
[retro-emulator](https://github.com/KevinBatdorf/nativephp-retro-emulator)
NativePHP Mobile plugin. Tap a console, play instantly — NES, SNES, Game
Boy, GBC, GBA, and Genesis, each with a bundled homebrew game, touch
controls, save states, rewind, an engine picker, and a CRT shader toggle.
It also ships a 96-step conformance suite that exercises the plugin's
entire bridge against the live native layer.

## Try it in two minutes

Grab the debug APK (GitHub release, or
`nativephp/android/app/build/outputs/apk/debug/app-debug.apk` after a
build):

```bash
adb install app-debug.apk
```

Tap a console. That's the demo. For the conformance suite, open
`/conformance` — it runs itself and shows **ALL GREEN** when done.

Want your own game in there? Drop a ROM on the device and it replaces the
bundled one — no rebuild:

```bash
adb push game.smc /data/local/tmp/ && adb exec-out run-as <app-id> sh -c \
  'mkdir -p app_storage/persisted_data/storage/app/roms/sfc && \
   cp /data/local/tmp/game.smc app_storage/persisted_data/storage/app/roms/sfc/'
```

## Run from source

PHP 8.3+, Composer, Node, Android Studio (SDK only — no NDK, the plugin
ships prebuilt libs). Clone the
[plugin](https://github.com/KevinBatdorf/nativephp-retro-emulator) next to
this repo (composer resolves it as a path repository until it's published).

```bash
composer install
cp .env.example .env && php artisan key:generate
npm install && npm run build
php artisan native:install
php artisan native:run android
```

After the first build, `php artisan native:jump` hot-reloads PHP/Blade
edits without rebuilding.

iOS: `php artisan native:install ios`, add
`pod 'RetroEmulator', :path => '<plugin repo>'` to the generated Podfile's
shared pods (re-add after every install — the generator only knows
published pods), then run with a UTF-8 locale:
`LANG=en_US.UTF-8 php artisan native:run ios`.

## Game credits

Every bundled game is freely-licensed homebrew — full texts in
[`resources/roms/license.txt`](resources/roms/license.txt):

- **Super Tilt Bro.** (NES) — Sylvain Gadrat, WTFPL
- **Space Rescue Squad** (SNES) — Marcus Rowe / KungFuFurby, zlib
- **Rex Runner GB** (Game Boy) — The Void, MIT
- **Tobu Tobu Girl Deluxe** (GBC) — Tangram Games (Simon Larsen), code MIT,
  assets CC-BY-4.0 (credit: "Tobu Tobu Girl by Tangram Games")
- **Miniplanets** (Genesis) — Javier Degirolmo (Sik), zlib
- **Blind Jump** (GBA) — Evan Bowman, MIT
- Alternates in `resources/roms/`: Tobu Tobu Girl (GB) — Tangram Games,
  MIT + CC-BY-4.0; Butano Fighter — Gustavo Valiente, zlib

## License

MIT for the demo's own code (see [`LICENSE`](LICENSE)); bundled games keep
their authors' licenses.
