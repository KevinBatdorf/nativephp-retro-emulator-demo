# Demo app rewrite — plan (step 2)

**Principle:** pure NativePHP (EDGE components + PHP — it's a plugin demo, no custom
native). Functional, not pretty. Goal is to showcase capabilities generically across
systems, not build a polished app. On-screen controls are plain `native:button`s wired
to the Controller handle API (also dogfoods input). The conformance runner stays as a
hidden dev route — still the gate.

## Screen 1 — Home (console list)

- Plain vertical list of consoles, name as the label (from `Emulator::systems()`, the
  compiled/supported ones). Tap → System screen.
- Bottom bar: **Settings**, **Quit** (kill app).
- Reset-all-to-defaults lives inside Settings.
- Settings = the global settings applied everywhere.

## Screen 2 — REVISED (2026-07-20): no ROM picker screen, no folder scan

Kevin's call: delete SystemScreen + Library's folder-scan/typed-path machinery
(and the BIOS path field — nothing requires a BIOS now). Also purge any
leftover hold-button references (plugins allowlist / provider).

- Home → tap console → /play/{id} boots the bundled homebrew ROM directly.
- ROM Settings gains "Load another ROM" → native file picker → COPY the picked
  file into app storage (pickers return content:// URIs / security-scoped
  URLs, not paths) → boot it. Imported ROMs persist in our own dir and list
  alongside the bundled game; battery saves key off basename so they work as-is.
- GATE: check whether nativephp/mobile dev-main ships a file/document picker
  (memory note saying "camera/gallery/scanner only" predates the repin to
  main). If missing: demo ships bundled-only, and the picker is an upstream
  nativephp/mobile contribution (Android SAF + UIDocumentPickerViewController
  behind one facade) — NOT another in-repo native element.

## Screen 3 — Play (running ROM)

- The emulator surface.
- Crude control overlay — EDGE buttons: d-pad, the system's own face buttons (from
  `getPorts()`), L/R, Start/Select → Controller press/release. Rough but playable.
- Sidebar with info: system, ROM, region, status (fps if easy).
- **ROM Settings** button → per-system settings screen.

## Settings model (persisted, two scopes)

- **Global** (Home → Settings): video (luminance/sat/gamma/overscan), audio
  (volume/balance), speed, **Apply CRT filter** on/off (one toggle, not a shader
  picker), rumble. Reset all to defaults.
- **Per-system** (Play → ROM Settings): region/preferred, per-system toggles (e.g. SFC
  `deepBlackBoost`), device selection, CRT override. Persisted per system id. Reset to
  defaults.

## Scope — explicit cuts

- Skip cheats.
- Skip write-to-memory/RAM tools.
- Shaders → single "Apply CRT filter" toggle.
- Crude/boring throughout, default EDGE styling only.

## Showcase extras (proposed — trim freely)

Screens 1–3 + settings + the cuts are specified. These headline features are already
built; flagging for approval, easy to cut:

- Save/load state (+undo) — a couple buttons in Play.
- Rewind / fast-forward / speed — simple buttons.
- Peripherals — device selector in per-system settings (mouse/Super Scope/multitap);
  headline of 1.5, could defer.
- Screenshot — one button.

## Build phases

1. Home (console list + bottom bar)
2. Global Settings + persistence + reset
3. System screen (folder picker, ROM scan, conditional BIOS)
4. Play (surface + crude overlay + sidebar)
5. Per-system settings + CRT toggle
6. Approved showcase extras

Keep the conformance route throughout.

## Open questions / risks

- **Folder picker:** confirm NativePHP exposes a folder picker + dir listing; else fall
  back to file-per-ROM.
- **CRT filter:** needs one bundled `.slangp` CRT preset for the toggle to apply.
- **Control latency:** EDGE-button → PHP-event → bridge per press; fine for a functional
  demo, but if it feels bad that's a real API finding.
- **Peripherals:** driving a mouse/light-gun is only meaningful with a matching game —
  may just expose the selector rather than fully demo.

## Two decisions to close before building

1. The showcase-extras set — all of them, or trim to just pad + CRT?
2. Folder picker vs file-per-ROM if NativePHP lacks folder selection.

---

## Build status (step 2 — BUILT)

Both decisions were resolved during the build (per "keep going, fix later"):
1. **All** showcase extras included (save/load/undo, rewind, fast-forward, speed ±,
   screenshot, peripheral selector) — all trivially removable from `play.blade.php` /
   `rom-settings.blade.php`.
2. **No folder picker exists** in NativePHP Mobile (only a photo/video gallery picker).
   Fallback shipped: a typed folder **path** (default `/data/local/tmp`) scanned with PHP
   `scandir` by the system's extensions, bundled homebrew ROMs always offered on top.

### Files
- Screens: `app/Native/{HomeScreen, SettingsScreen, SystemScreen, PlayScreen, RomSettingsScreen}.php`
- Views: `resources/views/{home, settings, system, play, rom-settings}.blade.php`
- Support: `app/Support/{Catalog, JsonStore, SettingsStore, Library}.php`
- Routes: `routes/web.php` (+ `start_url` → `/home` in `config/nativephp.php`); dev/conformance routes kept.

### Design notes / dogfooding
- Input is driven through the plugin's `Controller` handle (`setButtons`). EDGE only emits
  a discrete `@press` tap (no press-down/press-up pair), so on-screen buttons **toggle**
  held state (tap Right to walk, tap again to stop). Crude but the only model tap-only
  events allow.
- Overlay buttons come from `Emulator::ports()` at runtime (not hardcoded); `Catalog`
  only classifies them into dpad/face/shoulder/system groups.
- Settings assemble into the plugin's typed `SfcConfig/GbConfig/FcConfig/MdConfig` via
  `SettingsStore::configFor()` — verified: named-arg construction + `toArray()` correct
  per system, per-system overrides (region, deepBlackBoost) merge.

### Real API findings (surfaced by building the demo)
- **No folder/directory picker** exposed to PHP — only `Camera.PickMedia` (photo/video).
  A ROM folder chooser would need a new plugin bridge (`ACTION_OPEN_DOCUMENT_TREE` /
  `UIDocumentPickerViewController`).
- **No hard app-quit/kill API.** "Quit" uses `exitToWeb('/')` (drops to the web landing) —
  the closest available.
- **CRT toggle needs a bundled `.slangp` preset.** None ships yet; `SettingsStore::crtPreset()`
  searches `storage/app/shaders`, `resources/shaders`, `/data/local/tmp/shaders`. Until one
  is added the toggle no-ops with a toast (honest about the gap).
- **Control latency** (tap → PHP event → bridge per press) is real; the toggle-hold model
  keeps it playable.

### Verify — on-device on the Thor (PASSED, eyes-on)
Full flow driven + screencapped: Home (real consoles from `systems()`) → System (folder
note + scan + bundled ROM) → Play (surface shows **"Hello, World!"**, `sfc · auto ·
running`; overlay buttons B/Y/A/X from `getPorts()`; extras row). No crash.

Device-only bugs the on-device run caught (static checks could NOT):
1. **ParseError in `<native:>` tags** — single-quoted string literals inside `@navigate`
   / `:`-dynamic attributes (`$sys['id']`, `['rom'=>…]`, inline `['inherit',…]`) break the
   native-tag precompiler. Fix: hoist to `@php` vars / PHP handlers; never `{{ }}` inside a
   `:`-dynamic or `@navigate` attribute.
2. **`scandir('/data/local/tmp')` → Permission denied** — the app sandbox can't LIST that
   dir (native ares can still LOAD a path from it). Fix: `is_readable()` + `@scandir` guard
   in `Library::scan` (unreadable folder → bundled ROMs only, no fatal).
3. **Native SIGSEGV booting the Play surface** — see below.

### Play-surface boot: the hard-won pattern + the native crash fix
Booting the emulator during `mount()` or declaratively (rom as an element attribute) races
the plugin's Vulkan renderer: two render threads spawn, one publishes a half-initialised
`g_vk`, the other calls `VulkanRenderer::setSurface()` on it → **SIGSEGV** (null `instance_`
/ null `ANativeWindow`). The plugin's stable screens all boot imperatively AFTER the surface
exists, so PlayScreen boots on the first `#[Poll]` tick (ConformanceScreen's pattern).

That stopped the demo from *triggering* it, but the plugin should never segfault, so the
native bug was **fixed at the source** (sibling plugin repo, uncommitted):
- `android/app/src/main/cpp/ares_jni.cpp` — a `g_vkMutex` serialises the whole `g_vk`
  lifecycle (create/bind/resize/clear/destroy), and `g_vk` is published only AFTER
  `initDevice()` succeeds so no thread sees it half-built.
- `android/app/src/main/cpp/vulkan_renderer.cpp` — `setSurface()` returns false on a null
  window or `VK_NULL_HANDLE` instance/physical device instead of dereferencing.
- Rebuilt `.so` (`externalNativeBuildRelease`), stripped, dropped into
  `resources/android/jniLibs/{arm64-v8a,x86_64}/`. Verified: ROM boots, no crash.
