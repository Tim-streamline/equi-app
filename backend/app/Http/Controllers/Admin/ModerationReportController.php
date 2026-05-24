<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CommunityPost;
use App\Models\CommunityReply;
use App\Models\ModerationReport;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class ModerationReportController extends Controller
{
    public function index(Request $request): Response
    {
        $reports = ModerationReport::query()
            ->when($request->string('status', 'open')->toString(), fn ($query, $s) => $query->where('status', $s))
            ->with('reporter:id,name')
            ->latest()
            ->paginate(25)
            ->withQueryString()
            ->through(function (ModerationReport $r) {
                $subject = $r->subject_type === 'reply'
                    ? CommunityReply::find($r->subject_id)
                    : CommunityPost::find($r->subject_id);

                return [
                    ...$r->toArray(),
                    'reporter_name' => $r->reporter?->name,
                    'subject_excerpt' => $subject?->body ? mb_substr($subject->body, 0, 140) : '(deleted)',
                    'post_id' => $r->subject_type === 'reply' ? $subject?->post_id : $r->subject_id,
                ];
            });

        return Inertia::render('Reports/Index', [
            'reports' => $reports,
            'filters' => ['status' => $request->string('status', 'open')->toString()],
        ]);
    }

    public function resolve(Request $request, ModerationReport $report): RedirectResponse
    {
        $status = $request->validate(['status' => ['required', 'in:resolved,dismissed,reviewing']])['status'];
        $report->update([
            'status' => $status,
            'resolved_by' => $request->user('admin')->id,
            'resolved_at' => in_array($status, ['resolved', 'dismissed'], true) ? Carbon::now() : null,
        ]);
        AuditLogger::log('report_'.$status, $report, label: $report->reason);

        return back()->with('success', "Report marked {$status}.");
    }
}
