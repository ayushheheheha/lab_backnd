<?php

namespace App\Http\Controllers;

use App\Models\Attempt;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LeaderboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $rows = Attempt::query()
            ->where('is_complete', true)
            ->whereNotNull('submitted_at')
            ->whereNotNull('total_marks')
            ->where('total_marks', '>', 0)
            ->join('users', 'users.id', '=', 'attempts.user_id')
            ->select(
                'attempts.user_id',
                'users.name',
                DB::raw('ROUND(AVG((attempts.score / attempts.total_marks) * 100), 1) as avg_score'),
                DB::raw('COUNT(*) as attempt_count')
            )
            ->groupBy('attempts.user_id', 'users.name')
            ->orderByDesc('avg_score')
            ->orderByDesc('attempt_count')
            ->limit(50)
            ->get();

        $leaderboard = $rows->values()->map(function ($row, $index) {
            return [
                'rank'          => $index + 1,
                'user_id'       => $row->user_id,
                'name'          => $row->name,
                'avg_score'     => (float) $row->avg_score,
                'attempt_count' => (int) $row->attempt_count,
            ];
        });

        $myRank = null;
        $found = $leaderboard->firstWhere('user_id', $user->id);
        if ($found) {
            $myRank = $found['rank'];
        }

        return response()->json([
            'leaderboard' => $leaderboard,
            'my_rank'     => $myRank,
        ]);
    }
}
