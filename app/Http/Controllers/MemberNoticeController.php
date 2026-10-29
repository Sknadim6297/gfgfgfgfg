<?php

namespace App\Http\Controllers;

use App\Models\MemberNotice;
use App\Support\MemberNoticePrompt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MemberNoticeController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user('web');
        abort_unless($user?->role === 'user', 403);

        $notices = MemberNotice::query()
            ->visibleToMembers()
            ->orderedForDisplay()
            ->paginate(15);

        return view('pages.notices.index', [
            'user' => $user,
            'notices' => $notices,
        ]);
    }

    public function show(Request $request, MemberNotice $memberNotice): View
    {
        $user = $request->user('web');
        abort_unless($user?->role === 'user', 403);
        abort_unless($this->visible($memberNotice), 404);

        return view('pages.notices.show', [
            'user' => $user,
            'notice' => $memberNotice,
        ]);
    }

    public function dismiss(Request $request, MemberNotice $memberNotice): JsonResponse|RedirectResponse
    {
        abort_unless($request->user('web')?->role === 'user', 403);
        abort_if($request->session()->has('admin_impersonation'), 403);
        abort_unless(
            MemberNotice::query()->forPopup()->whereKey($memberNotice->id)->exists(),
            404,
        );

        MemberNoticePrompt::dismiss($request, $memberNotice->id);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Notice dismissed for this sign-in.']);
        }

        return back();
    }

    private function visible(MemberNotice $notice): bool
    {
        return MemberNotice::query()->visibleToMembers()->whereKey($notice->id)->exists();
    }
}
