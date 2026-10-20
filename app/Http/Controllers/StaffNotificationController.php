<?php

namespace App\Http\Controllers;

use App\Services\StaffNotifications;
use Illuminate\Http\Request;

class StaffNotificationController extends Controller
{
    public function index(Request $request, StaffNotifications $notifications)
    {
        $data = $request->validate(['page' => ['nullable', 'integer', 'min:1', 'max:100000']]);

        return response()->json($notifications->feed($request->user(), (int) ($data['page'] ?? 1)))
            ->header('Cache-Control', 'private, no-store');
    }

    public function read(Request $request, StaffNotifications $notifications)
    {
        $data = $request->validate(['through_id' => ['required', 'integer', 'min:0']]);
        $notifications->markRead($request->user(), (int) $data['through_id']);

        return back();
    }

    public function open(Request $request, int $notification, StaffNotifications $notifications)
    {
        $document = $notifications->open($request->user(), $notification);

        return $document ? to_route('documents.show', $document)
            : back()->with('success', 'That task has already changed. Your notifications are up to date.');
    }
}
