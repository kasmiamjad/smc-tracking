<?php

use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

// The admin console is a single-page app built into public/admin (see ../admin).
// Real files there are served directly by the web server; every other /admin/*
// path is a client-side route, so hand back the app shell.
Route::get('/admin/{path?}', function () {
    $shell = public_path('admin/index.html');
    abort_unless(is_file($shell), 503, 'Admin console has not been built. Run "npm run build" in admin/.');

    return response()->file($shell, ['Cache-Control' => 'no-cache']);
})->where('path', '.*');
