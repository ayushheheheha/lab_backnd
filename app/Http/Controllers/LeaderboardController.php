<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\XpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeaderboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $viewer = $request->user();

        $rows = User::query()
            ->where('is_active', true)
            ->where('is_admin', false)
            ->where('xp', '>', 0)
            ->orderByDesc('xp')
            ->orderBy('id')
            ->limit(50)
            ->get(['id', 'name', 'avatar', 'xp']);

        $leaderboard = $rows->values()->map(function ($u, $index) {
            $level = XpService::levelFromXp((int) $u->xp);
            return [
                'rank'    => $index + 1,
                'user_id' => $u->id,
                'name'    => $u->name,
                'avatar'  => $u->avatar,
                'xp'      => (int) $u->xp,
                'level'   => $level,
            ];
        });

        $myRow = $leaderboard->firstWhere('user_id', $viewer->id);
        $myRank = $myRow['rank'] ?? null;

        if (! $myRank) {
            $myXp = (int) ($viewer->xp ?? 0);
            $ahead = User::query()
                ->where('is_active', true)
                ->where('is_admin', false)
                ->where('xp', '>', $myXp)
                ->count();
            $myRank = $myXp > 0 ? $ahead + 1 : null;
        }

        return response()->json([
            'leaderboard' => $leaderboard,
            'my_rank'     => $myRank,
            'me' => [
                'xp' => (int) ($viewer->xp ?? 0),
                'level' => XpService::levelFromXp((int) ($viewer->xp ?? 0)),
            ],
        ]);
    }
}
