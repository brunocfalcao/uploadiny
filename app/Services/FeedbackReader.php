<?php

declare(strict_types=1);

namespace App\Services;

use App\Project;
use App\UploadChunk;
use App\UploadImage;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

final class FeedbackReader
{
    /** Seconds after the last feedback save during which the owner may still be editing. */
    public const SETTLING_SECONDS = 60;

    /** @return array<string, mixed> */
    public function projects(): array
    {
        $projects = Project::query()->withCount(['images' => fn ($query) => $query->whereHas('chunk', fn ($chunk) => $chunk->where('status', 'complete'))])
            ->addSelect(['latest_chunk_id' => UploadChunk::query()->select('id')->where('status', 'complete')
                ->whereHas('images', fn ($query) => $query->whereColumn('project_id', 'projects.id'))->newestFinishedFirst()->limit(1)])
            ->orderBy('name')->get();
        $ids = $projects->pluck('latest_chunk_id')->filter()->unique();
        $chunks = UploadChunk::query()->whereKey($ids)->get(['id', 'uuid', 'completed_at'])->keyBy('id');
        $counts = UploadImage::query()->selectRaw('chunk_id, project_id, count(*) as asset_count')->whereIn('chunk_id', $ids)
            ->groupBy('chunk_id', 'project_id')->get()->keyBy(fn (UploadImage $row): string => $row->chunk_id.':'.$row->project_id);

        return ['projects' => $projects->map(function (Project $project) use ($chunks, $counts): array {
            $chunk = $chunks->get($project->getAttribute('latest_chunk_id'));

            return [
                'id' => $project->id, 'name' => $project->name, 'slug' => $project->slug,
                'canonical' => $project->canonical, 'description' => $project->description,
                'image_count' => $project->images_count,
                'latest_chunk' => $chunk ? ['id' => $chunk->uuid, 'completed_at' => $chunk->completed_at?->toIso8601String(), 'asset_count' => (int) $counts->get($chunk->id.':'.$project->id)?->getAttribute('asset_count')] : null,
            ];
        })->all()];
    }

    /** @return array<string, mixed> */
    public function latest(Project $project, bool $includeDescriptions = true, ?string $afterChunk = null): array
    {
        $query = $project->chunks();
        if ($afterChunk !== null) {
            $cursor = $project->chunks()->where('uuid', $afterChunk)->first();
            if ($cursor === null) {
                throw ValidationException::withMessages(['after_chunk' => 'The cursor batch is unavailable in this project. Fetch without after_chunk to establish a new cursor.']);
            }
            $query->finishedAfter($cursor);
        }
        $chunk = $query->with(['images' => fn ($query) => $query->where('project_id', $project->id)->orderBy('id')])->newestFinishedFirst()->first();
        if ($chunk === null) {
            return ['project' => $this->projectData($project), 'chunk' => null];
        }
        $images = $chunk->images->map(fn (UploadImage $image): array => $this->image($image, $includeDescriptions))->values()->all();

        return [
            'project' => $this->projectData($project),
            'chunk' => ['id' => $chunk->uuid, 'uploaded_at' => $chunk->uploadedAt()->toIso8601String(), 'review_token' => $chunk->reviewToken($project, $chunk->images), 'settling' => in_array(true, array_column($images, 'feedback_settling'), true), 'images' => $images],
        ];
    }

    /**
     * Compact agent view of one asset: browser-only drawing points are replaced by marks.
     *
     * @return array<string, mixed>
     */
    public function image(UploadImage $image, bool $includeDescriptions = true): array
    {
        $data = $image->agentData();
        if (! $includeDescriptions) {
            unset($data['description'], $data['description_status'], $data['description_error'], $data['description_model']);
        }
        $annotations = $data['annotations'];
        unset($data['annotations']);
        $marks = $this->marks($annotations);

        return $data + [
            'mark_count' => count($marks),
            'marks' => $marks,
            'feedback_updated_at' => $image->feedback_updated_at?->toIso8601String(),
            'feedback_settling' => $image->feedback_updated_at !== null && $image->feedback_updated_at->gt(Carbon::now()->subSeconds(self::SETTLING_SECONDS)),
        ];
    }

    /** @return array{id: int, name: string, slug: string, canonical: string} */
    private function projectData(Project $project): array
    {
        return ['id' => $project->id, 'name' => $project->name, 'slug' => $project->slug, 'canonical' => $project->canonical];
    }

    /**
     * @param  array<int, mixed>  $annotations
     * @return list<array<string, mixed>>
     */
    private function marks(array $annotations): array
    {
        $marks = [];
        foreach (array_values($annotations) as $index => $annotation) {
            if (! is_array($annotation)) {
                continue;
            }
            $tool = is_string($annotation['tool'] ?? null) ? $annotation['tool'] : 'unknown';
            $points = $this->points($annotation['points'] ?? []);
            $callout = $tool === 'callout' && count($points) >= 4;
            $area = $this->box($callout ? array_slice($points, 0, 2) : $points);
            $text = $callout && is_string($annotation['text'] ?? null) && $annotation['text'] !== '' ? $annotation['text'] : null;
            $line = in_array($tool, ['arrow', 'line'], true) && count($points) >= 2;

            $marks[] = [
                'n' => $index + 1,
                'tool' => $tool,
                'color' => is_string($annotation['color'] ?? null) ? $annotation['color'] : null,
                'position' => $area === null ? null : $this->position($area),
                'area' => $area,
                'note' => $text,
                'note_area' => $callout ? $this->box(array_slice($points, 2, 2)) : null,
                'from' => $line ? $this->round($points[0]) : null,
                'to' => $line ? $this->round($points[count($points) - 1]) : null,
            ];
        }

        return $marks;
    }

    /** @return list<array{x: float, y: float}> */
    private function points(mixed $points): array
    {
        if (! is_array($points)) {
            return [];
        }
        $clean = [];
        foreach ($points as $point) {
            if (is_array($point) && is_numeric($point['x'] ?? null) && is_numeric($point['y'] ?? null)) {
                $clean[] = ['x' => (float) $point['x'], 'y' => (float) $point['y']];
            }
        }

        return $clean;
    }

    /**
     * @param  list<array{x: float, y: float}>  $points
     * @return array{x: float, y: float, width: float, height: float}|null
     */
    private function box(array $points): ?array
    {
        if ($points === []) {
            return null;
        }
        $xs = array_column($points, 'x');
        $ys = array_column($points, 'y');

        return [
            'x' => round(min($xs), 3), 'y' => round(min($ys), 3),
            'width' => round(max($xs) - min($xs), 3), 'height' => round(max($ys) - min($ys), 3),
        ];
    }

    /**
     * @param  array{x: float, y: float}  $point
     * @return array{x: float, y: float}
     */
    private function round(array $point): array
    {
        return ['x' => round($point['x'], 3), 'y' => round($point['y'], 3)];
    }

    /** @param array{x: float, y: float, width: float, height: float} $area */
    private function position(array $area): string
    {
        $column = $this->third($area['x'] + $area['width'] / 2);
        $row = $this->third($area['y'] + $area['height'] / 2);

        return match ([$row, $column]) {
            [0, 0] => 'top-left', [0, 1] => 'top-center', [0, 2] => 'top-right',
            [1, 0] => 'middle-left', [1, 1] => 'middle', [1, 2] => 'middle-right',
            [2, 0] => 'bottom-left', [2, 1] => 'bottom-center', default => 'bottom-right',
        };
    }

    private function third(float $value): int
    {
        return $value < 1 / 3 ? 0 : ($value < 2 / 3 ? 1 : 2);
    }
}
