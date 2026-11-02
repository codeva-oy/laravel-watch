<?php

use Codeva\Watch\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('watch/health', HealthController::class)->name('watch.health');
