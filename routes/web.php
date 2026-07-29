<?php

use App\Native\AudioTestScreen;
use App\Native\ConformanceScreen;
use App\Native\DeclarativeScreen;
use App\Native\DpadGalleryScreen;
use App\Native\ErrorsScreen;
use App\Native\HomeScreen;
use App\Native\PlayScreen;
use App\Native\ProbeScreen;
use App\Native\ShaderProbeScreen;
use App\Native\SystemsScreen;
use Illuminate\Support\Facades\Route;

// The app is native-only (start_url = /home); nothing should land here.
Route::get('/', fn () => redirect('/home'));

// ── Demo app (home + play; settings is an in-place overlay in PlayScreen) ──
Route::native('/home', HomeScreen::class);
Route::native('/play/{id}', PlayScreen::class);

// ── Hidden dev routes (the conformance gate + probes) ─
Route::native('/conformance', ConformanceScreen::class);
Route::native('/systems', SystemsScreen::class);
Route::native('/probe', ProbeScreen::class);
Route::native('/declarative', DeclarativeScreen::class);
Route::native('/errors', ErrorsScreen::class);
Route::native('/audio', AudioTestScreen::class);
Route::native('/shader', ShaderProbeScreen::class);
Route::native('/dpads', DpadGalleryScreen::class);
