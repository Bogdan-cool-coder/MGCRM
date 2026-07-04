<?php

use Illuminate\Support\Facades\Route;

// API-only application — the real UI is the standalone Vue SPA in front/.
// The web entrypoint carries no user-facing routes; a bare 204 keeps root
// health-probes and reverse-proxy checks happy without rendering a view.
Route::get('/', fn () => response()->noContent());
