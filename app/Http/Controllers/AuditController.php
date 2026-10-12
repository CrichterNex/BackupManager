<?php

namespace App\Http\Controllers;

use App\Models\AuditEntry;
use App\Support\Page;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    public function index(Request $request)
    {
        $q = $request->validate([
            'action' => ['sometimes', 'string', 'max:100'],
            'user_id' => ['sometimes', 'integer', 'min:1'],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date'],
        ]);
        $query = AuditEntry::query()->leftJoin('users as u', 'u.id', '=', 'audit_trail.user_id')
            ->select('audit_trail.id', 'audit_trail.user_id', 'u.username', 'audit_trail.action', 'audit_trail.resource_type', 'audit_trail.resource_id', 'audit_trail.details', 'audit_trail.ip_address', 'audit_trail.success', 'audit_trail.created_at');
        isset($q['action']) && $query->where('audit_trail.action', $q['action']);
        isset($q['user_id']) && $query->where('audit_trail.user_id', $q['user_id']);
        isset($q['date_from']) && $query->where('audit_trail.created_at', '>=', $q['date_from']);
        isset($q['date_to']) && $query->where('audit_trail.created_at', '<=', $q['date_to']);
        $query->orderByDesc('audit_trail.id');
        [$rows, $pagination] = Page::run($request, $query);

        return response()->json(['success' => true, 'data' => $rows, 'pagination' => $pagination]);
    }
}
