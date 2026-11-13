<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Notificaciones internas del usuario autenticado (campanita). */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'unread' => $user->unreadNotifications()->count(),
            'items' => $user->notifications()->latest()->limit(15)->get()->map(fn ($n) => [
                'id' => $n->id,
                'icon' => $n->data['icon'] ?? 'bell',
                'title' => $n->data['title'] ?? 'Notificación',
                'body' => $n->data['body'] ?? '',
                'url' => $n->data['url'] ?? null,
                'read' => $n->read_at !== null,
                'at' => $n->created_at->toIso8601String(),
            ]),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        // Solo se puede marcar una notificación propia: el scope por usuario evita tocar las ajenas.
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        return response()->json(['ok' => true]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['ok' => true]);
    }
}
