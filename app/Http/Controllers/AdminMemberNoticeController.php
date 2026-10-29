<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveMemberNoticeRequest;
use App\Models\AuditLog;
use App\Models\MemberNotice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminMemberNoticeController extends Controller
{
    public function index(): View
    {
        $notices = MemberNotice::query()
            ->with('createdBy:id,name')
            ->orderedForDisplay()
            ->paginate(15);

        return view('admin.notices.index', [
            'notices' => $notices,
        ]);
    }

    public function create(): View
    {
        return view('admin.notices.form', [
            'notice' => new MemberNotice([
                'type' => 'information',
                'status' => 'draft',
                'priority' => 'normal',
                'popup_enabled' => true,
            ]),
        ]);
    }

    public function store(SaveMemberNoticeRequest $request): RedirectResponse
    {
        $notice = MemberNotice::query()->create([
            ...$request->noticeAttributes(),
            'created_by_user_id' => $request->user('admin')->id,
        ]);
        $this->audit($request, $notice, 'admin.member_notice_created');

        return redirect()
            ->route('admin.notices.index')
            ->with('status', 'Notice saved.');
    }

    public function edit(MemberNotice $memberNotice): View
    {
        return view('admin.notices.form', [
            'notice' => $memberNotice,
        ]);
    }

    public function update(SaveMemberNoticeRequest $request, MemberNotice $memberNotice): RedirectResponse
    {
        $before = $this->snapshot($memberNotice);
        $memberNotice->fill($request->noticeAttributes());
        $memberNotice->save();
        $this->audit($request, $memberNotice, 'admin.member_notice_updated', $before);

        return redirect()
            ->route('admin.notices.index')
            ->with('status', 'Notice updated.');
    }

    public function updateStatus(Request $request, MemberNotice $memberNotice): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(['published', 'inactive'])],
        ]);
        $before = $this->snapshot($memberNotice);
        $memberNotice->status = $validated['status'];
        $memberNotice->save();
        $this->audit($request, $memberNotice, 'admin.member_notice_updated', $before);

        return back()->with('status', $memberNotice->status === 'published' ? 'Notice published.' : 'Notice deactivated.');
    }

    public function destroy(Request $request, MemberNotice $memberNotice): RedirectResponse
    {
        $before = $this->snapshot($memberNotice);
        $memberNotice->delete();
        $this->audit($request, $memberNotice, 'admin.member_notice_deleted', $before);

        return redirect()
            ->route('admin.notices.index')
            ->with('status', 'Notice deleted.');
    }

    private function audit(Request $request, MemberNotice $notice, string $event, ?array $before = null): void
    {
        AuditLog::query()->create([
            'actor_user_id' => $request->user('admin')->id,
            'subject_user_id' => $request->user('admin')->id,
            'event' => $event,
            'auditable_type' => MemberNotice::class,
            'auditable_id' => $notice->id,
            'metadata' => array_filter([
                'before' => $before,
                'after' => $this->snapshot($notice),
            ]),
            'ip_address' => $request->ip(),
        ]);
    }

    private function snapshot(MemberNotice $notice): array
    {
        return [
            'title' => $notice->title,
            'type' => $notice->type,
            'status' => $notice->status,
            'priority' => $notice->priority,
            'popup_enabled' => (bool) $notice->popup_enabled,
            'publish_at' => $notice->publish_at?->toIso8601String(),
            'expires_at' => $notice->expires_at?->toIso8601String(),
        ];
    }
}
