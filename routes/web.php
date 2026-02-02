<?php

use App\Http\Controllers\PodcastFeedController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canRegister' => Features::enabled(Features::registration()),
    ]);
})->name('home');


Route::get('feeds/{channel}/{programme}', PodcastFeedController::class)->name('feeds.show');

require __DIR__.'/settings.php';
