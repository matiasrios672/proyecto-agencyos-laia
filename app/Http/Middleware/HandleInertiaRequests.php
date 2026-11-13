<?php

namespace App\Http\Middleware;

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
        return [
            ...parent::share($request),
            'app' => ['name' => config('app.name')],
            'auth' => fn () => $request->user() ? [
                'user' => [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'email' => $request->user()->email,
                    'job_title' => $request->user()->job_title,
                    'role' => $request->user()->roleName(),
                    'role_label' => config('laia.role_labels.'.$request->user()->roleName()),
                    'is_sysadmin' => $request->user()->isSysadmin(),
                ],
                'permissions' => $request->user()->isSysadmin()
                    ? collect(config('laia.permissions'))->flatMap(fn ($g) => array_keys($g))->values()
                    : $request->user()->getAllPermissions()->pluck('name')->values(),
            ] : null,
            // Contador de la campana listo al renderizar: evita el parpadeo del badge al navegar entre páginas.
            'unread_notifications' => fn () => $request->user()?->unreadNotifications()->count() ?? 0,
            'flash' => fn () => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
        ];
    }
}
