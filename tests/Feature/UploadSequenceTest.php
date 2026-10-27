<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Project;
use App\Services\ChunkStorage;
use App\Services\ImageCopy;
use App\Services\PrivateFileWrites;
use App\UploadChunk;
use App\UploadImage;
use App\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class UploadSequenceTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['false'])]
    #[TestWith(['partial'])]
    #[TestWith(['move'])]
    public function test_upload_service_keeps_the_previous_counter_when_publication_fails(string $failure): void
    {
        [$project, $disk] = $this->prepareUpload();
        $unblock = $this->blockSequencePublication($disk, $failure);

        try {
            app(ChunkStorage::class)->store($project, [UploadedFile::fake()->image('failed.png')]);
            $this->fail('The upload must fail when its sequence publication is incomplete.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('previous counter was kept', $error->getMessage());
        }

        $this->assertSame('120', $disk->get('.uploadiny-sequence'));
        $this->assertTrue($project->images()->doesntExist());
        $this->assertSame([], $disk->allFiles('images'));
        $disk->assertMissing('.uploadiny-sequence.next');

        $unblock();
        $chunk = app(ChunkStorage::class)->store($project, [UploadedFile::fake()->image('retry.png')]);

        $this->assertSame('upload-121.png', $chunk->images->sole()->name);
        $this->assertSame('121', $disk->get('.uploadiny-sequence'));
    }

    #[TestWith(['false'])]
    #[TestWith(['partial'])]
    #[TestWith(['move'])]
    public function test_public_upload_keeps_the_previous_counter_when_publication_fails(string $failure): void
    {
        [$project, $disk] = $this->prepareUpload();
        $unblock = $this->blockSequencePublication($disk, $failure);

        $this->postJson(route('chunks.store', $project), ['files' => [UploadedFile::fake()->image('failed.png')]])
            ->assertStatus(500);

        $this->assertSame('120', $disk->get('.uploadiny-sequence'));
        $this->assertTrue($project->images()->doesntExist());
        $this->assertSame([], $disk->allFiles('images'));
        $disk->assertMissing('.uploadiny-sequence.next');

        $unblock();
        $response = $this->postJson(route('chunks.store', $project), ['files' => [UploadedFile::fake()->image('retry.png')]])
            ->assertCreated();

        $this->assertSame('upload-121.png', $response->json('images.0.name'));
        $this->assertSame('121', $disk->get('.uploadiny-sequence'));
    }

    #[TestWith(['false'])]
    #[TestWith(['partial'])]
    #[TestWith(['move'])]
    public function test_copy_service_keeps_the_previous_counter_when_publication_fails(string $failure): void
    {
        [$project, $source, $target, $disk] = $this->prepareCopy();
        $unblock = $this->blockSequencePublication($disk, $failure);

        try {
            app(PrivateFileWrites::class)->transaction(fn (PrivateFileWrites $writes): UploadImage => app(ImageCopy::class)->intoChunk($source, $target, $project->id, $writes));
            $this->fail('The copy must fail when its sequence publication is incomplete.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('previous counter was kept', $error->getMessage());
        }

        $this->assertSame('120', $disk->get('.uploadiny-sequence'));
        $this->assertSame(1, $target->images()->count());
        $disk->assertExists($source->path);
        $this->assertSame(['images/source.png'], $disk->allFiles('images'));
        $disk->assertMissing('.uploadiny-sequence.next');

        $unblock();
        $copy = app(PrivateFileWrites::class)->transaction(fn (PrivateFileWrites $writes): UploadImage => app(ImageCopy::class)->intoChunk($source->fresh(), $target, $project->id, $writes));

        $this->assertSame('upload-121.png', $copy->name);
        $this->assertSame('121', $disk->get('.uploadiny-sequence'));
        $this->assertSame(2, $target->fresh()->images()->count());
    }

    #[TestWith(['false'])]
    #[TestWith(['partial'])]
    #[TestWith(['move'])]
    public function test_public_copy_keeps_the_previous_counter_when_publication_fails(string $failure): void
    {
        [$project, $source, , $disk] = $this->prepareCopy();
        $this->actingAs(User::factory()->create(['email' => 'copy-sequence@example.test']));
        $unblock = $this->blockSequencePublication($disk, $failure);

        $this->postJson(route('images.duplicate', $source))->assertStatus(500);

        $this->assertSame('120', $disk->get('.uploadiny-sequence'));
        $this->assertSame(1, $source->chunk->images()->count());
        $this->assertSame(['images/source.png'], $disk->allFiles('images'));
        $disk->assertMissing('.uploadiny-sequence.next');

        $unblock();
        $response = $this->postJson(route('images.duplicate', $source))->assertCreated();

        $this->assertSame('upload-121.png', $response->json('name'));
        $this->assertSame('121', $disk->get('.uploadiny-sequence'));
        $this->assertSame(2, $source->chunk->images()->count());
    }

    #[TestWith([''])]
    #[TestWith(['not-a-number'])]
    public function test_a_present_damaged_counter_fails_closed_instead_of_resetting(string $value): void
    {
        [$project, $disk] = $this->prepareUpload();
        $disk->put('.uploadiny-sequence', $value);

        try {
            app(ChunkStorage::class)->store($project, [UploadedFile::fake()->image('damaged.png')]);
            $this->fail('A damaged sequence counter must stop a new upload.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('saved upload numbering needs recovery', $error->getMessage());
            $this->assertStringContainsString('Contact support before retrying', $error->getMessage());
        }

        $this->assertSame($value, $disk->get('.uploadiny-sequence'));
        $this->assertTrue($project->images()->doesntExist());
    }

    /** @return array{Project, object} */
    private function prepareUpload(): array
    {
        Storage::fake('local');
        Queue::fake();
        $this->actingAs(User::factory()->create(['email' => 'sequence-owner@example.test']));
        $project = Project::factory()->create(['slug' => 'sequence-upload']);
        $disk = Storage::disk('local');
        $disk->put('.uploadiny-sequence', '120');

        return [$project, $disk];
    }

    /** @return array{Project, UploadImage, UploadChunk, object} */
    private function prepareCopy(): array
    {
        Storage::fake('local');
        Queue::fake();
        $project = Project::factory()->create(['slug' => 'sequence-copy']);
        $chunk = UploadChunk::factory()->create(['upload_project_id' => $project->id, 'status' => 'complete', 'completed_at' => now()]);
        $source = UploadImage::factory()->create([
            'project_id' => $project->id,
            'chunk_id' => $chunk->id,
            'name' => 'upload-17.png',
            'path' => 'images/source.png',
            'description_status' => 'ready',
        ]);
        $disk = Storage::disk('local');
        $disk->put('.uploadiny-sequence', '120');
        $disk->put($source->path, 'source bytes');

        return [$project, $source, $chunk, $disk];
    }

    private function blockSequencePublication(object $disk, string $failure): Closure
    {
        $blocked = true;
        $proxy = Mockery::mock($disk);
        $proxy->shouldReceive('put')->andReturnUsing(function (...$arguments) use ($disk, $failure, &$blocked): bool {
            [$path, $value] = $arguments;
            if ($blocked && $path === '.uploadiny-sequence.next') {
                if ($failure === 'false') {
                    return false;
                }
                if ($failure === 'partial') {
                    $disk->put($path, substr((string) $value, 0, -1));

                    return true;
                }
            }

            return $disk->put(...$arguments);
        });
        $proxy->shouldReceive('move')->andReturnUsing(function (...$arguments) use ($disk, $failure, &$blocked): bool {
            [$from, $to] = $arguments;
            if ($blocked && $failure === 'move' && $to === '.uploadiny-sequence') {
                return false;
            }

            return $disk->move(...$arguments);
        });
        Storage::shouldReceive('disk')->with('local')->andReturn($proxy);

        return static function () use (&$blocked): void {
            $blocked = false;
        };
    }
}
