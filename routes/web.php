<?php

use App\Native\AudioTestScreen;
use App\Native\ConformanceScreen;
use App\Native\DeclarativeScreen;
use App\Native\ErrorsScreen;
use App\Native\HomeScreen;
use App\Native\PlayScreen;
use App\Native\ProbeScreen;
use App\Native\RomSettingsScreen;
use App\Native\SettingsScreen;
use App\Native\ShaderProbeScreen;
use App\Native\SystemsScreen;
use Illuminate\Support\Facades\Route;

// The app is native-only (start_url = /home); nothing should land here.
Route::get('/', fn () => redirect('/home'));

// ── Demo app (screens 1–3 + settings) ───────────────
Route::native('/home', HomeScreen::class);
Route::native('/settings', SettingsScreen::class);
// More specific route first so /play/{id}/settings never matches /play/{id}.
Route::native('/play/{id}/settings', RomSettingsScreen::class);
Route::native('/play/{id}', PlayScreen::class);

// ── Hidden dev routes (the conformance gate + probes) ─
Route::native('/conformance', ConformanceScreen::class);
Route::native('/systems', SystemsScreen::class);
Route::native('/probe', ProbeScreen::class);
Route::native('/declarative', DeclarativeScreen::class);
Route::native('/errors', ErrorsScreen::class);
Route::native('/audio', AudioTestScreen::class);
Route::native('/shader', ShaderProbeScreen::class);
