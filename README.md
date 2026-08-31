# Retro Emulator — Demo

Demo app for the [retro-emulator](https://github.com/KevinBatdorf/nativephp-retro-emulator) NativePHP Mobile plugin — boots each supported system with a bundled homebrew game to check the plugin works on a real device.

- NES
- SNES
- Game Boy
- Game Boy Color
- Game Boy Advance
- Genesis

I added an open source homebrew rom for each system to test with, but you can load your own in app as well.

## Android

Install the debug APK (GitHub release, or `nativephp/android/app/build/outputs/apk/debug/app-debug.apk` after a build):

```bash
adb install app-debug.apk
```

Or from source — PHP 8.4+, Composer, Node, Android Studio (SDK only, no NDK), and a [NativePHP Mobile](https://nativephp.com/mobile) license for `composer install`. Have `ANDROID_HOME` pointing at your SDK (Android Studio's default is `~/Library/Android/sdk` on macOS) — the generated project can't find it otherwise. The plugin installs from Packagist like any package; the first build downloads its prebuilt emulator cores from the plugin's release (checksum-verified, cached after that):

```bash
composer install
cp .env.example .env && php artisan key:generate
npm install && npm run build
php artisan native:install
php artisan native:run android
```

After the first build, `php artisan native:jump` hot-reloads PHP/Blade edits without rebuilding.

## iOS

Same source setup — the plugin's build hook downloads the prebuilt framework and wires the Podfile itself:

```bash
php artisan native:install ios
LANG=en_US.UTF-8 php artisan native:run ios
```

Simulator works out of the box; a physical iPhone needs `NATIVEPHP_DEVELOPMENT_TEAM` in `.env` and Developer Mode enabled.

## Game credits

Every bundled game is freely-licensed homebrew — full texts in [`resources/roms/license.txt`](resources/roms/license.txt):

- **Super Tilt Bro.** — Sylvain Gadrat, WTFPL
- **Space Rescue Squad** — Marcus Rowe / KungFuFurby, zlib
- **GB-Wordyl** — bbbbbr, GPL-3.0 (unmodified build; source at github.com/bbbbbr/gb-wordyl)
- **Tobu Tobu Girl Deluxe** — Tangram Games (Simon Larsen), code MIT, assets CC-BY-4.0 (credit: "Tobu Tobu Girl by Tangram Games")
- **Miniplanets** — Javier Degirolmo (Sik), zlib
- **Blind Jump** — Evan Bowman, MIT
- Alternates in `resources/roms/`: Rex Runner GB — The Void, MIT; Tobu Tobu Girl (GB) — Tangram Games, MIT + CC-BY-4.0; Butano Fighter — Gustavo Valiente, zlib

## License

MIT for the demo's own code (see [`LICENSE`](LICENSE)); bundled games keep their authors' licenses.
