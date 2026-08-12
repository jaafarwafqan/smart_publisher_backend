<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
 * Public, unauthenticated legal pages — real HTTPS URLs for Meta App
 * Dashboard's Privacy Policy / Terms of Service / User Data Deletion
 * fields (2026-08-12). Content mirrors docs/legal/*.md (the internal
 * source of truth this repo has carried since the closed beta), filled in
 * with the operator's real published facts rather than the placeholders
 * those docs deliberately left blank.
 */
Route::get('/legal/privacy-policy', fn () => view('legal.privacy-policy'));
Route::get('/legal/terms-of-service', fn () => view('legal.terms-of-service'));
Route::get('/legal/data-deletion', fn () => view('legal.data-deletion'));

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
