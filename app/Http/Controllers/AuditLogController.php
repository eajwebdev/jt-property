<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $logs = ActivityLog::query()->with('user:id,name')
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(fn ($q) => $q->where('action', 'like', '%'.$search.'%')->orWhereHas('user', fn ($u) => $u->where('name', 'like', '%'.$search.'%'))))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('created_at', '<=', $to))
            ->latest('id')->paginate(30)->withQueryString()->through(fn (ActivityLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'user' => $log->user?->name ?? 'System',
                'subject' => class_basename($log->subject_type ?? '').' #'.$log->subject_id,
                'ip' => $log->ip_address,
                'at' => $log->created_at?->toDateTimeString(),
            ]);

        return Inertia::render('audit/index', ['logs' => $logs, 'filters' => $filters]);
    }
}
