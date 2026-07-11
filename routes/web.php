<?php

use App\Native\ConformanceScreen;
use App\Native\ProbeScreen;
use App\Native\SystemsScreen;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::native('/conformance', ConformanceScreen::class);
Route::native('/systems', SystemsScreen::class);
Route::native('/probe', ProbeScreen::class);
