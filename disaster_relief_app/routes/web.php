<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/index', function () {
    return view('welcome');
});

Route::any('/signin', function () {
    return view('signin');
});

Route::any('/signup', function () {
    return view('signup');
});

$pages = [
    'admin_dashboard', 'affected_dashboard', 'affected_login', 'affected_register',
    'camp_manager_dashboard', 'campaigns', 'donate', 'donor_dashboard',
    'guest', 'logout', 'volunteer_dashboard'
];

foreach ($pages as $page) {
    Route::any('/' . $page, function () use ($page) {
        return view($page);
    });
}

Route::any('/{page}.php', function ($page) {
    if ($page === 'index') {
        return view('welcome');
    }
    if (view()->exists($page)) {
        return view($page);
    }
    abort(404);
});
