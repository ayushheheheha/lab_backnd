<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Discussion;
use App\Models\DiscussionReply;
use App\Models\DiscussionVote;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DiscussionController extends Controller
{
    private const XP_ON_ACCEPT = 50;

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'course_id'   => 'nullable|integer|exists:courses,id',
            'course_slug' => 'nullable|string',
            'subject'     => ['nullable', Rule::in(array_keys(Discussion::SUBJECTS))],
            'sort'        => ['nullable', Rule::in(['newest', 'trending', 'solved', 'unsolved'])],
            'search'      => 'nullable|string|max:120',
            'mine'        => 'nullable|boolean',
        ]);

        $userId = $request->user()->id;
        $sort   = $data['sort'] ?? 'newest';

        $query = Discussion::query()
            ->with([
                'user:id,name,is_admin,avatar',
                'course:id,name,slug',
                'linkedQuiz:id,title',
            ]);

        if (!empty($data['course_slug'])) {
            $course = Course::where('slug', $data['course_slug'])->first();
            $query->where('course_id', $course?->id);
        } elseif (!empty($data['course_id'])) {
            $query->where('course_id', $data['course_id']);
        }

        if (!empty($data['subject'])) {
            $query->where('subject', $data['subject']);
        }

        if (!empty($data['search'])) {
            $q = $data['search'];
            $query->where(function ($w) use ($q) {
                $w->where('title', 'like', "%{$q}%")
                  ->orWhere('body', 'like', "%{$q}%");
            });
        }

        if (!empty($data['mine'])) {
            $query->where('user_id', $userId);
        }

        if ($sort === 'solved')   $query->where('is_solved', true);
        if ($sort === 'unsolved') $query->where('is_solved', false);

        if ($sort === 'trending') {
            $query->orderByDesc('vote_count')->orderByDesc('created_at');
        } else {
            $query->orderByDesc('created_at');
        }

        $discussions = $query->limit(50)->get();

        $myVotedSet = array_flip(
            DiscussionVote::query()
                ->where('user_id', $userId)
                ->where('votable_type', Discussion::class)
                ->whereIn('votable_id', $discussions->pluck('id'))
                ->pluck('votable_id')
                ->all()
        );

        return response()->json(
            $discussions->map(fn ($d) => $this->serializeListItem($d, $myVotedSet, $userId))->values()
        );
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $viewer = $request->user();
        $userId = $viewer->id;

        $discussion = Discussion::with([
            'user:id,name,is_admin,avatar',
            'course:id,name,slug',
            'linkedQuiz:id,title',
            'replies.user:id,name,is_admin,avatar',
        ])->findOrFail($id);

        if ($discussion->user_id !== $userId) {
            $inserted = DB::table('discussion_views')->insertOrIgnore([
                'discussion_id' => $discussion->id,
                'user_id'       => $userId,
                'viewed_at'     => now(),
            ]);
            if ($inserted) {
                $discussion->increment('view_count');
            }
        }

        $replyIds = $discussion->replies->pluck('id')->all();

        $voteRows = DiscussionVote::query()
            ->where('user_id', $userId)
            ->where(function ($w) use ($discussion, $replyIds) {
                $w->where(function ($q) use ($discussion) {
                    $q->where('votable_type', Discussion::class)
                      ->where('votable_id', $discussion->id);
                });
                if ($replyIds) {
                    $w->orWhere(function ($q) use ($replyIds) {
                        $q->where('votable_type', DiscussionReply::class)
                          ->whereIn('votable_id', $replyIds);
                    });
                }
            })
            ->get(['votable_type', 'votable_id']);

        $myVotedDiscussion = $voteRows->contains(
            fn ($v) => $v->votable_type === Discussion::class
        );
        $myVotedReplySet = array_flip(
            $voteRows
                ->where('votable_type', DiscussionReply::class)
                ->pluck('votable_id')
                ->all()
        );

        $replies = $discussion->replies
            ->sort(function ($a, $b) use ($discussion) {
                $aAccepted = $discussion->accepted_reply_id === $a->id ? 1 : 0;
                $bAccepted = $discussion->accepted_reply_id === $b->id ? 1 : 0;
                if ($aAccepted !== $bAccepted) return $bAccepted - $aAccepted;

                if ($a->is_endorsed !== $b->is_endorsed) return ($b->is_endorsed ? 1 : 0) - ($a->is_endorsed ? 1 : 0);

                if ($a->vote_count !== $b->vote_count) return $b->vote_count - $a->vote_count;

                return $a->created_at <=> $b->created_at;
            })
            ->values();

        return response()->json([
            'id'                => $discussion->id,
            'title'             => $discussion->title,
            'body'              => $discussion->body,
            'subject'           => $discussion->subject,
            'subject_label'     => Discussion::SUBJECTS[$discussion->subject] ?? null,
            'course'            => $discussion->course ? [
                'id'   => $discussion->course->id,
                'name' => $discussion->course->name,
                'slug' => $discussion->course->slug,
            ] : null,
            'linked_quiz'       => $discussion->linkedQuiz ? [
                'id'    => $discussion->linkedQuiz->id,
                'title' => $discussion->linkedQuiz->title,
            ] : null,
            'is_anonymous'      => $discussion->is_anonymous,
            'is_solved'         => $discussion->is_solved,
            'accepted_reply_id' => $discussion->accepted_reply_id,
            'vote_count'        => $discussion->vote_count,
            'reply_count'       => $discussion->reply_count,
            'view_count'        => $discussion->view_count,
            'author'            => $this->serializeAuthor(
                $discussion->user, $discussion->is_anonymous, $discussion->user_id, $userId, (bool) $viewer->is_admin
            ),
            'is_mine'           => $discussion->user_id === $userId,
            'my_vote'           => $myVotedDiscussion,
            'created_at'        => optional($discussion->created_at)->toIso8601String(),
            'replies'           => $replies
                ->map(fn ($r) => $this->serializeReply($r, $discussion, $myVotedReplySet, $userId, (bool) $viewer->is_admin))
                ->values()
                ->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title'          => 'required|string|max:200',
            'body'           => 'required|string|max:5000',
            'course_id'      => 'nullable|integer|exists:courses,id',
            'subject'        => ['nullable', Rule::in(array_keys(Discussion::SUBJECTS))],
            'linked_quiz_id' => 'nullable|integer|exists:quizzes,id',
            'is_anonymous'   => 'nullable|boolean',
        ]);

        $discussion = Discussion::create([
            'user_id'        => $request->user()->id,
            'course_id'      => $data['course_id'] ?? null,
            'subject'        => $data['subject'] ?? null,
            'title'          => $data['title'],
            'body'           => $data['body'],
            'is_anonymous'   => $data['is_anonymous'] ?? false,
            'linked_quiz_id' => $data['linked_quiz_id'] ?? null,
        ]);

        return response()->json(['id' => $discussion->id], 201);
    }

    public function reply(Request $request, int $id): JsonResponse
    {
        $discussion = Discussion::findOrFail($id);

        $data = $request->validate([
            'body'         => 'required|string|max:5000',
            'is_anonymous' => 'nullable|boolean',
        ]);

        $reply = DiscussionReply::create([
            'discussion_id' => $discussion->id,
            'user_id'       => $request->user()->id,
            'body'          => $data['body'],
            'is_anonymous'  => $data['is_anonymous'] ?? false,
        ]);

        $discussion->increment('reply_count');

        return response()->json(['id' => $reply->id], 201);
    }

    public function voteDiscussion(Request $request, int $id): JsonResponse
    {
        $discussion = Discussion::findOrFail($id);
        $userId     = $request->user()->id;

        return DB::transaction(function () use ($discussion, $userId) {
            $existing = DiscussionVote::where('user_id', $userId)
                ->where('votable_type', Discussion::class)
                ->where('votable_id', $discussion->id)
                ->first();

            if ($existing) {
                $existing->delete();
                $discussion->decrement('vote_count');
                return response()->json(['voted' => false, 'count' => max(0, $discussion->vote_count - 1)]);
            }

            DiscussionVote::create([
                'user_id'      => $userId,
                'votable_type' => Discussion::class,
                'votable_id'   => $discussion->id,
            ]);
            $discussion->increment('vote_count');
            return response()->json(['voted' => true, 'count' => $discussion->vote_count + 1]);
        });
    }

    public function voteReply(Request $request, int $id): JsonResponse
    {
        $reply  = DiscussionReply::findOrFail($id);
        $userId = $request->user()->id;

        return DB::transaction(function () use ($reply, $userId) {
            $existing = DiscussionVote::where('user_id', $userId)
                ->where('votable_type', DiscussionReply::class)
                ->where('votable_id', $reply->id)
                ->first();

            if ($existing) {
                $existing->delete();
                $reply->decrement('vote_count');
                return response()->json(['voted' => false, 'count' => max(0, $reply->vote_count - 1)]);
            }

            DiscussionVote::create([
                'user_id'      => $userId,
                'votable_type' => DiscussionReply::class,
                'votable_id'   => $reply->id,
            ]);
            $reply->increment('vote_count');
            return response()->json(['voted' => true, 'count' => $reply->vote_count + 1]);
        });
    }

    public function accept(Request $request, int $id): JsonResponse
    {
        $discussion = Discussion::findOrFail($id);

        if ($discussion->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Only the discussion author can mark an answer'], 403);
        }

        $data  = $request->validate(['reply_id' => 'required|integer|exists:discussion_replies,id']);
        $reply = DiscussionReply::where('discussion_id', $discussion->id)->findOrFail($data['reply_id']);

        if ($reply->user_id === $discussion->user_id) {
            return response()->json(['message' => 'Cannot accept your own reply'], 422);
        }

        return DB::transaction(function () use ($discussion, $reply) {
            if ($discussion->accepted_reply_id && $discussion->accepted_reply_id !== $reply->id) {
                $prev = DiscussionReply::find($discussion->accepted_reply_id);
                if ($prev) {
                    User::where('id', $prev->user_id)->decrement('xp', self::XP_ON_ACCEPT);
                }
            }

            $isNew                      = $discussion->accepted_reply_id !== $reply->id;
            $discussion->accepted_reply_id = $reply->id;
            $discussion->is_solved      = true;
            $discussion->save();

            if ($isNew) {
                User::where('id', $reply->user_id)->increment('xp', self::XP_ON_ACCEPT);
            }

            return response()->json([
                'accepted_reply_id' => $reply->id,
                'is_solved'         => true,
                'xp_awarded'        => $isNew ? self::XP_ON_ACCEPT : 0,
            ]);
        });
    }

    public function unaccept(Request $request, int $id): JsonResponse
    {
        $discussion = Discussion::findOrFail($id);
        if ($discussion->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Only the discussion author can unmark an answer'], 403);
        }
        if (!$discussion->accepted_reply_id) {
            return response()->json(['is_solved' => false]);
        }

        return DB::transaction(function () use ($discussion) {
            $prev = DiscussionReply::find($discussion->accepted_reply_id);
            if ($prev) {
                User::where('id', $prev->user_id)->decrement('xp', self::XP_ON_ACCEPT);
            }
            $discussion->accepted_reply_id = null;
            $discussion->is_solved         = false;
            $discussion->save();
            return response()->json(['is_solved' => false]);
        });
    }

    public function endorse(Request $request, int $id): JsonResponse
    {
        if (!$request->user()->is_admin) {
            return response()->json(['message' => 'Only instructors can endorse answers'], 403);
        }

        $reply = DiscussionReply::findOrFail($id);
        $reply->is_endorsed = !$reply->is_endorsed;
        $reply->save();

        return response()->json(['is_endorsed' => $reply->is_endorsed]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $discussion = Discussion::findOrFail($id);
        $user       = $request->user();

        if ($discussion->user_id !== $user->id && !$user->is_admin) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $discussion->delete();
        return response()->json(['ok' => true]);
    }

    public function destroyReply(Request $request, int $id): JsonResponse
    {
        $reply = DiscussionReply::findOrFail($id);
        $user  = $request->user();

        if ($reply->user_id !== $user->id && !$user->is_admin) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $discussion = $reply->discussion;
        $wasAccepted = $discussion && $discussion->accepted_reply_id === $reply->id;
        $reply->delete();

        if ($discussion) {
            $discussion->decrement('reply_count');
            if ($wasAccepted) {
                User::where('id', $reply->user_id)->decrement('xp', self::XP_ON_ACCEPT);
                $discussion->accepted_reply_id = null;
                $discussion->is_solved         = false;
                $discussion->save();
            }
        }

        return response()->json(['ok' => true]);
    }

    public function subjects(): JsonResponse
    {
        return response()->json(Discussion::SUBJECTS);
    }

    // -------- Serializers --------

    private function serializeListItem(Discussion $d, array $myVotedSet, int $userId): array
    {
        return [
            'id'            => $d->id,
            'title'         => $d->title,
            'body_preview'  => mb_strimwidth(strip_tags($d->body), 0, 180, '…'),
            'subject'       => $d->subject,
            'subject_label' => Discussion::SUBJECTS[$d->subject] ?? null,
            'course'        => $d->course ? ['id' => $d->course->id, 'name' => $d->course->name, 'slug' => $d->course->slug] : null,
            'linked_quiz'   => $d->linkedQuiz ? ['id' => $d->linkedQuiz->id, 'title' => $d->linkedQuiz->title] : null,
            'is_anonymous'  => $d->is_anonymous,
            'is_solved'     => $d->is_solved,
            'vote_count'    => $d->vote_count,
            'reply_count'   => $d->reply_count,
            'view_count'    => $d->view_count,
            'author'        => $this->serializeAuthor($d->user, $d->is_anonymous, $d->user_id, $userId, false),
            'is_mine'       => $d->user_id === $userId,
            'my_vote'       => isset($myVotedSet[$d->id]),
            'created_at'    => optional($d->created_at)->toIso8601String(),
        ];
    }

    private function serializeReply(DiscussionReply $r, Discussion $d, array $myVotedReplySet, int $userId, bool $viewerIsAdmin): array
    {
        return [
            'id'           => $r->id,
            'body'         => $r->body,
            'is_anonymous' => $r->is_anonymous,
            'is_endorsed'  => $r->is_endorsed,
            'is_accepted'  => $d->accepted_reply_id === $r->id,
            'vote_count'   => $r->vote_count,
            'author'       => $this->serializeAuthor($r->user, $r->is_anonymous, $r->user_id, $userId, $viewerIsAdmin),
            'is_mine'      => $r->user_id === $userId,
            'my_vote'      => isset($myVotedReplySet[$r->id]),
            'created_at'   => optional($r->created_at)->toIso8601String(),
        ];
    }

    private function serializeAuthor(?User $user, bool $isAnon, int $authorId, int $viewerId, bool $viewerIsAdmin): array
    {
        if (!$user) {
            return ['id' => null, 'name' => 'Unknown', 'avatar' => null, 'is_admin' => false, 'is_anonymous' => false, 'is_self' => false];
        }

        $showReal = !$isAnon || $authorId === $viewerId || $viewerIsAdmin;

        return [
            'id'           => $showReal ? $user->id : null,
            'name'         => $showReal ? $user->name : 'Anonymous Student',
            'avatar'       => $showReal ? $user->avatar : null,
            'is_admin'     => $showReal ? (bool) $user->is_admin : false,
            'is_anonymous' => $isAnon,
            'is_self'      => $authorId === $viewerId,
        ];
    }
}
