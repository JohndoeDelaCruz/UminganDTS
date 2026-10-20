<?php

namespace App\Http\Middleware;

use App\Services\StaffNotifications;
use App\Services\WorkspaceActivity;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'name' => config('app.name'),
            'quote' => ['message' => 'Every document, accounted for.', 'author' => 'Umingan DTS'],
            'auth' => [
                'user' => $request->user()?->load('department'),
            ],
            'unreadActivity' => fn () => $request->user() ? app(WorkspaceActivity::class)->counts($request->user()) : null,
            'staffNotifications' => fn () => $request->user() && ! $request->routeIs('track*') ? app(StaffNotifications::class)->feed($request->user()) : null,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
            ],
        ]);
    }
}
