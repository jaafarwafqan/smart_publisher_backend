<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
 * Meta redirects browser-based mobile OAuth flows only to a publicly
 * reachable HTTPS URL. This relay deliberately does not consume the code or
 * state: the authenticated API callback validates the single-use state and
 * exchanges the code. Its sole purpose is to resume the installed Android
 * app through the deep link declared in AndroidManifest.xml.
 */
Route::get('/oauth/facebook/callback', function (Request $request) {
    $parameters = [];

    foreach (['code', 'state', 'error', 'error_reason', 'error_description'] as $key) {
        $value = $request->query($key);

        if (is_string($value) && $value !== '') {
            $parameters[$key] = $value;
        }
    }

    $deepLink = 'smartpublisher://oauth/callback';

    if ($parameters !== []) {
        $deepLink .= '?'.http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }

    return redirect()->away($deepLink)
        ->header('Cache-Control', 'no-store, private')
        ->header('Referrer-Policy', 'no-referrer');
});
