<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        // Laravel skips AJAX GETs when recording the previous URL. Inertia pages
        // still need a current, same-origin destination for validation redirects.
        if ($request->isMethod('GET') && $response->isSuccessful() && $request->hasSession()
            && ! $request->prefetch() && ! $request->routeIs('attachments.download', 'reports.export', 'workspace.activity', 'notifications.index', 'health')) {
            $request->session()->setPreviousUrl($request->fullUrl());
        }
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        return $response;
    }
}
