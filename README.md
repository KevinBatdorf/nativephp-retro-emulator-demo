# Retro Emulator — Demo

The showcase app for the
[retro-emulator](https://github.com/KevinBatdorf/nativephp-retro-emulator)
NativePHP Mobile plugin. Tap a console, play instantly — touch controls,
save states, rewind, fast-forward, an engine picker, and a CRT shader.

- **NES** — Super Tilt Bro.
- **SNES** — Space Rescue Squad
- **Game Boy** — Rex Runner
- **Game Boy Color** — Tobu Tobu Girl Deluxe
- **Game Boy Advance** — Blind Jump
- **Genesis** — Miniplanets

## Test it on Android

Grab the debug APK (GitHub release, or
`nativephp/android/app/build/outputs/apk/debug/app-debug.apk` after a
build) — no toolchain needed:

```bash
adb install app-debug.apk
```

Or from source (PHP 8.3+, Composer, Node, Android Studio — SDK only, no
NDK). Clone the
[plugin](https://github.com/KevinBatdorf/nativephp-retro-emulator) next to
this repo first; composer resolves it as a path repository:

```bash
composer install
cp .env.example .env && php artisan key:generate
npm install && npm run build
php artisan native:install
php artisan native:run android
```

After the first build, `php artisan native:jump` hot-reloads PHP/Blade
edits without rebuilding.

## Test it on iOS

Same source setup, then build the plugin's framework and wire the pod —
the Podfile generator only knows published pods, so add the path pod by
hand (and re-add it after any `native:install ios`):

```bash
(cd ../nativephp-retro-emulator && ./scripts/build_xcframework.sh)
php artisan native:install ios
# in nativephp/ios/Podfile, inside the shared pods block:
#   pod 'RetroEmulator', :path => '../../../nativephp-retro-emulator'
LANG=en_US.UTF-8 php artisan native:run ios
```

Simulator works out of the box; a physical iPhone needs
`NATIVEPHP_DEVELOPMENT_TEAM` in `.env` and Developer Mode enabled.

## Game credits

Every bundled game is freely-licensed homebrew — full texts in
[`resources/roms/license.txt`](resources/roms/license.txt):

- **Super Tilt Bro.** — Sylvain Gadrat, WTFPL
- **Space Rescue Squad** — Marcus Rowe / KungFuFurby, zlib
- **Rex Runner GB** — The Void, MIT
- **Tobu Tobu Girl Deluxe** — Tangram Games (Simon Larsen), code MIT,
  assets CC-BY-4.0 (credit: "Tobu Tobu Girl by Tangram Games")
- **Miniplanets** — Javier Degirolmo (Sik), zlib
- **Blind Jump** — Evan Bowman, MIT
- Alternates in `resources/roms/`: Tobu Tobu Girl (GB) — Tangram Games,
  MIT + CC-BY-4.0; Butano Fighter — Gustavo Valiente, zlib

## License

MIT for the demo's own code (see [`LICENSE`](LICENSE)); bundled games keep
their authors' licenses.
