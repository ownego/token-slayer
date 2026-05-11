<?php

use App\Http\Controllers\Auth\SlackController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/auth/slack', [SlackController::class, 'redirect'])->name('slack.login');
Route::get('/auth/slack/callback', [SlackController::class, 'callback']);

Route::get('/profile', fn () => view('profile'))->middleware('auth')->name('profile');

Route::get('/battlefield', fn () => view('battlefield'))->name('battlefield');
