<?php

namespace App\Services;

use App\Models\Course;
use App\Models\VideoSolution;
use Illuminate\Support\Facades\DB;

class VideoImporter
{
    /**
     * @param  array  $rows  Raw video objects from the JSON payload.
     * @param  array  $defaults  ['course_id' => ?int, 'is_pro' => ?bool, 'is_published' => ?bool]
     * @return array{rows: array<int, array>, errors: array<int, array>}
     */
    public function normalizeMany(array $rows, array $defaults = []): array
    {
        $courseLookup = $this->buildCourseLookup();
        $errors = [];
        $normalized = [];

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                $errors[] = $this->importError($index, 'video', 'Each video must be a JSON object.');
                continue;
            }

            [$normalizedRow, $rowErrors] = $this->normalize($row, $index, $courseLookup, $defaults);

            if (! empty($rowErrors)) {
                $errors = [...$errors, ...$rowErrors];
                continue;
            }

            if ($normalizedRow) {
                $normalized[] = $normalizedRow;
            }
        }

        return ['rows' => $normalized, 'errors' => $errors];
    }

    /**
     * @return array<int, int> Inserted video ids.
     */
    public function persist(array $normalizedRows): array
    {
        $insertedIds = [];

        DB::transaction(function () use ($normalizedRows, &$insertedIds) {
            $nextSort = (int) VideoSolution::query()->max('sort_order');

            foreach ($normalizedRows as $row) {
                if (! isset($row['sort_order'])) {
                    $row['sort_order'] = ++$nextSort;
                }

                $video = VideoSolution::query()->create($row);
                $insertedIds[] = $video->id;
            }
        });

        return $insertedIds;
    }

    private function normalize(array $row, int $index, array $courseLookup, array $defaults): array
    {
        $errors = [];

        $title = trim((string) ($row['title'] ?? ''));
        if ($title === '') {
            $errors[] = $this->importError($index, 'title', 'Title is required.');
        }

        // Resolve provider + id from an explicit link or explicit fields.
        $link = trim((string) ($row['link'] ?? $row['url'] ?? ''));
        $explicitProvider = strtolower(trim((string) ($row['provider'] ?? '')));
        $resolved = $this->resolveSource($link, $explicitProvider, $row);

        if ($resolved === null) {
            $errors[] = $this->importError($index, 'link', 'Could not detect a valid YouTube or Google Drive video from "link".');
        }

        // Resolve course (optional). Accepts course_id, or a name/slug via "course".
        $courseId = $this->resolveCourse($row, $courseLookup, $defaults, $index, $errors);

        // Chapters (optional) — accept array or JSON string.
        $chapters = $this->normalizeChapters($row['chapters'] ?? null);

        if (! empty($errors)) {
            return [null, $errors];
        }

        $isPro = array_key_exists('is_pro', $row)
            ? $this->toBool($row['is_pro'])
            : (bool) ($defaults['is_pro'] ?? false);

        $isPublished = array_key_exists('is_published', $row)
            ? $this->toBool($row['is_published'])
            : (bool) ($defaults['is_published'] ?? true);

        $data = [
            'course_id'     => $courseId,
            'title'         => $title,
            'provider'      => $resolved['provider'],
            'drive_file_id' => $resolved['drive_file_id'],
            'youtube_id'    => $resolved['youtube_id'],
            'description'   => $this->nullableString($row['description'] ?? null),
            'author'        => $this->nullableString($row['author'] ?? null),
            'duration'      => $this->nullableString($row['duration'] ?? null),
            'thumbnail_url' => $this->nullableString($row['thumbnail_url'] ?? $row['thumbnail'] ?? null),
            'chapters'      => $chapters,
            'is_pro'        => $isPro,
            'is_published'  => $isPublished,
        ];

        if (isset($row['sort_order']) && is_numeric($row['sort_order'])) {
            $data['sort_order'] = (int) $row['sort_order'];
        }

        return [$data, []];
    }

    /**
     * Detect provider + the underlying id from a URL (or bare id + explicit provider).
     *
     * @return array{provider: string, youtube_id: ?string, drive_file_id: ?string}|null
     */
    public function resolveSource(string $link, string $explicitProvider = '', array $row = []): ?array
    {
        // Explicit per-field ids take priority.
        $youtubeId = trim((string) ($row['youtube_id'] ?? ''));
        $driveId = trim((string) ($row['drive_file_id'] ?? ''));

        if ($youtubeId !== '' && $this->looksLikeYoutubeId($youtubeId)) {
            return ['provider' => 'youtube', 'youtube_id' => $youtubeId, 'drive_file_id' => null];
        }
        if ($driveId !== '') {
            return ['provider' => 'google_drive', 'youtube_id' => null, 'drive_file_id' => $driveId];
        }

        if ($link === '') {
            return null;
        }

        $isYoutubeUrl = preg_match('/youtu\.?be|youtube(-nocookie)?\.com/i', $link) === 1;
        $isDriveUrl = preg_match('/drive\.google\.com|docs\.google\.com/i', $link) === 1;

        if ($isYoutubeUrl || $explicitProvider === 'youtube') {
            $id = $this->extractYoutubeId($link);
            return $id ? ['provider' => 'youtube', 'youtube_id' => $id, 'drive_file_id' => null] : null;
        }

        if ($isDriveUrl || $explicitProvider === 'google_drive' || $explicitProvider === 'drive') {
            $id = $this->extractDriveId($link);
            return $id ? ['provider' => 'google_drive', 'youtube_id' => null, 'drive_file_id' => $id] : null;
        }

        // Bare id with no hints: 11 chars → YouTube, longer → Drive.
        if ($this->looksLikeYoutubeId($link)) {
            return ['provider' => 'youtube', 'youtube_id' => $link, 'drive_file_id' => null];
        }
        if (preg_match('/^[A-Za-z0-9_-]{12,}$/', $link) === 1) {
            return ['provider' => 'google_drive', 'youtube_id' => null, 'drive_file_id' => $link];
        }

        return null;
    }

    private function extractYoutubeId(string $link): ?string
    {
        $patterns = [
            '#youtu\.be/([A-Za-z0-9_-]{11})#',
            '#[?&]v=([A-Za-z0-9_-]{11})#',
            '#/embed/([A-Za-z0-9_-]{11})#',
            '#/shorts/([A-Za-z0-9_-]{11})#',
            '#/v/([A-Za-z0-9_-]{11})#',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $link, $m) === 1) {
                return $m[1];
            }
        }

        return $this->looksLikeYoutubeId($link) ? $link : null;
    }

    private function extractDriveId(string $link): ?string
    {
        if (preg_match('#/d/([A-Za-z0-9_-]{10,})#', $link, $m) === 1) {
            return $m[1];
        }
        if (preg_match('#[?&]id=([A-Za-z0-9_-]{10,})#', $link, $m) === 1) {
            return $m[1];
        }

        return preg_match('/^[A-Za-z0-9_-]{10,}$/', $link) === 1 ? $link : null;
    }

    private function looksLikeYoutubeId(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{11}$/', $value) === 1;
    }

    private function resolveCourse(array $row, array $courseLookup, array $defaults, int $index, array &$errors): ?int
    {
        if (isset($row['course_id']) && is_numeric($row['course_id'])) {
            $id = (int) $row['course_id'];
            if (! in_array($id, $courseLookup['ids'], true)) {
                $errors[] = $this->importError($index, 'course_id', "Course id {$id} does not exist.");
                return null;
            }
            return $id;
        }

        $courseRef = trim((string) ($row['course'] ?? $row['course_name'] ?? $row['course_slug'] ?? ''));
        if ($courseRef !== '') {
            $key = mb_strtolower($courseRef);
            if (isset($courseLookup['byName'][$key])) {
                return $courseLookup['byName'][$key];
            }
            if (isset($courseLookup['bySlug'][$key])) {
                return $courseLookup['bySlug'][$key];
            }
            $errors[] = $this->importError($index, 'course', "No course matches \"{$courseRef}\".");
            return null;
        }

        // Fall back to the importer-wide default course (may be null = unassigned).
        return isset($defaults['course_id']) ? (int) $defaults['course_id'] : null;
    }

    private function buildCourseLookup(): array
    {
        $courses = Course::query()->get(['id', 'name', 'slug']);

        return [
            'ids'    => $courses->pluck('id')->all(),
            'byName' => $courses->mapWithKeys(fn ($c) => [mb_strtolower($c->name) => $c->id])->all(),
            'bySlug' => $courses->mapWithKeys(fn ($c) => [mb_strtolower($c->slug) => $c->id])->all(),
        ];
    }

    private function normalizeChapters(mixed $raw): ?array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        if (! is_array($raw) || empty($raw)) {
            return null;
        }

        $chapters = collect($raw)
            ->map(function ($ch) {
                if (! is_array($ch)) {
                    return null;
                }
                $time = trim((string) ($ch['time'] ?? ''));
                $titleText = trim((string) ($ch['title'] ?? ''));
                if ($time === '' && $titleText === '') {
                    return null;
                }
                return ['time' => $time, 'title' => $titleText];
            })
            ->filter()
            ->values()
            ->all();

        return empty($chapters) ? null : $chapters;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value !== '' ? $value : null;
    }

    private function toBool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
    }

    private function importError(int $rowIndex, string $field, string $message): array
    {
        return [
            'row' => $rowIndex + 1,
            'field' => $field,
            'message' => $message,
        ];
    }
}
