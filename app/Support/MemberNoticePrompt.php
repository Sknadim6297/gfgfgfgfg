<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class MemberNoticePrompt
{
    public const SESSION_KEY = 'member_notice_prompt';

    public static function begin(Request $request): void
    {
        $request->session()->put(self::SESSION_KEY, [
            'token' => (string) Str::uuid(),
            'dismissed' => [],
        ]);
    }

    public static function ensure(Request $request): void
    {
        $token = self::token($request);

        if ($token === null) {
            self::begin($request);
        }
    }

    public static function token(Request $request): ?string
    {
        $state = $request->session()->get(self::SESSION_KEY);
        $token = is_array($state) ? ($state['token'] ?? null) : null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    public static function dismissedIds(Request $request): array
    {
        $state = $request->session()->get(self::SESSION_KEY);
        $ids = is_array($state) ? ($state['dismissed'] ?? []) : [];

        if (! is_array($ids)) {
            return [];
        }

        $normalized = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $ids),
            static fn (int $id): bool => $id > 0,
        )));

        return array_slice($normalized, 0, 100);
    }

    public static function dismiss(Request $request, int $noticeId): void
    {
        self::ensure($request);
        $ids = self::dismissedIds($request);

        if (! in_array($noticeId, $ids, true)) {
            $ids[] = $noticeId;
        }

        $state = $request->session()->get(self::SESSION_KEY);
        $state = is_array($state) ? $state : [];
        $state['dismissed'] = array_slice($ids, 0, 100);
        $request->session()->put(self::SESSION_KEY, $state);
    }
}
