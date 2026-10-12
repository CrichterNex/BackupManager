<?php

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Support\ApiException;
use App\Support\Page;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $me = $request->user();
        $unreadOnly = $request->query('unread') === 'true';
        $query = AppNotification::where('user_id', $me->id)->when($unreadOnly, fn ($q) => $q->where('is_read', false))->orderByDesc('created_at')->orderByDesc('id');
        [$rows, $pagination] = Page::run($request, $query);

        return response()->json([
            'success' => true, 'data' => $rows,
            'unread_count' => AppNotification::where('user_id', $me->id)->where('is_read', false)->count(),
            'pagination' => $pagination,
        ]);
    }

    public function readAll(Request $request)
    {
        $n = AppNotification::where('user_id', $request->user()->id)->where('is_read', false)->update(['is_read' => true, 'read_at' => now()]);

        return response()->json(['success' => true, 'marked' => $n]);
    }

    public function read(Request $request, int $id)
    {
        $n = AppNotification::where('user_id', $request->user()->id)->find($id) ?? throw ApiException::notFound('Notification');
        $n->forceFill(['is_read' => true, 'read_at' => $n->read_at ?? now()])->save();

        return response()->json(['success' => true]);
    }
}
