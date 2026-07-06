<?php

declare(strict_types=1);

use Kitcchu\UserInteractionTracker\Http\Controllers\UserInteractionController;
use Illuminate\Support\Facades\Route;

$cfg    = config('user-interaction-tracker.route', []);
$path   = $cfg['path'] ?? 'api/track-interaction';
$mw     = $cfg['middleware'] ?? [];
$name   = $cfg['name'] ?? 'user-interaction.track';

Route::post($path, [UserInteractionController::class, 'track'])
    ->middleware($mw)
    ->name($name);
