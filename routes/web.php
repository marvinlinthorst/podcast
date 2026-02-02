<?php

use App\Http\Controllers\PodcastFeedController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::get('feeds/{channel}/{programme}', PodcastFeedController::class)->name('feeds.show');
