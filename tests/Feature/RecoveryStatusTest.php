<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\PrivateFileWrites;
use App\Services\WorkspaceDeletion;
use App\UploadImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToRetrieveMetadata;
use Mockery;
use Tests\TestCase;

class RecoveryStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_orphan_status_skips_a_journal_retired_during_metadata_sampling(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $journal = 'deleting/race/journal.json';
        $disk->put($journal, '{}');
        $proxy = Mockery::mock($disk);
        $proxy->shouldReceive('directories')->once()->with('deleting')->andReturn(['deleting/race']);
        $proxy->shouldReceive('exists')->once()->with($journal)->andReturnTrue();
        $proxy->shouldReceive('exists')->once()->with('deleting/race/recovery.json')->andReturnFalse();
        $proxy->shouldReceive('lastModified')->once()->with($journal)->andThrow(UnableToRetrieveMetadata::lastModified($journal));
        Storage::shouldReceive('disk')->with('local')->andReturn($proxy);

        $status = app(WorkspaceDeletion::class)->orphanRecoveryStatus();

        $this->assertSame(['pending' => 0, 'oldest_pending_seconds' => null, 'attempts' => 0, 'deferred' => 0, 'last_attempt_at' => null], $status);
    }

    public function test_orphan_status_ignores_invalid_progress_timestamps_without_leaking_metadata(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $journal = 'deleting/invalid/journal.json';
        $disk->put($journal, '{}');
        $disk->put('deleting/invalid/recovery.json', json_encode([
            'created_at' => 'not-a-timestamp', 'attempts' => 4, 'last_attempt_at' => 'also-not-a-timestamp', 'last_error' => 'RuntimeException',
        ], JSON_THROW_ON_ERROR));

        $status = app(WorkspaceDeletion::class)->orphanRecoveryStatus();
        $encoded = json_encode($status, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $status['pending']);
        $this->assertSame(4, $status['attempts']);
        $this->assertNull($status['last_attempt_at']);
        $this->assertSame(1, $status['deferred']);
        $this->assertStringNotContainsString('deleting/invalid', $encoded);
        $this->assertStringNotContainsString('not-a-timestamp', $encoded);
    }

    public function test_orphan_status_skips_a_journal_that_disappears_after_progress_reading(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $journal = 'deleting/retired/journal.json';
        $disk->put($journal, '{}');
        $disk->put('deleting/retired/recovery.json', json_encode([
            'created_at' => now()->subMinute()->toIso8601String(), 'attempts' => 1, 'last_attempt_at' => null, 'last_error' => null,
        ], JSON_THROW_ON_ERROR));
        $proxy = Mockery::mock($disk);
        $proxy->shouldReceive('directories')->once()->with('deleting')->andReturn(['deleting/retired']);
        $proxy->shouldReceive('exists')->with($journal)->andReturnValues([true, false]);
        $proxy->shouldReceive('exists')->once()->with('deleting/retired/recovery.json')->andReturnTrue();
        $proxy->shouldReceive('get')->once()->with('deleting/retired/recovery.json')->andReturn($disk->get('deleting/retired/recovery.json'));
        Storage::shouldReceive('disk')->with('local')->andReturn($proxy);

        $status = app(WorkspaceDeletion::class)->orphanRecoveryStatus();

        $this->assertSame(['pending' => 0, 'oldest_pending_seconds' => null, 'attempts' => 0, 'deferred' => 0, 'last_attempt_at' => null], $status);
    }

    public function test_cleanup_status_reports_writing_recovery_and_active_leases_without_private_paths(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $this->travelTo('2026-10-27 01:00:00');
        $journal = 'writing/deferred/journal.json';
        $disk->put($journal, json_encode(['images/private.png'], JSON_THROW_ON_ERROR));
        $disk->put('writing/deferred/recovery.json', json_encode([
            'created_at' => now()->subSeconds(75)->toIso8601String(), 'attempts' => 2, 'last_attempt_at' => now()->subSeconds(10)->toIso8601String(), 'last_error' => 'RuntimeException',
        ], JSON_THROW_ON_ERROR));
        $activeJournal = 'writing/active/journal.json';
        $disk->put($activeJournal, json_encode(['images/active-private.png'], JSON_THROW_ON_ERROR));
        $hashes = [hash('sha256', $disk->get($journal)), hash('sha256', $disk->get('writing/deferred/recovery.json'))];
        $lock = fopen($disk->path('writing/active/.lock'), 'c');
        $this->assertNotFalse($lock);
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));

        try {
            $status = app(PrivateFileWrites::class)->recoveryStatus();
            $this->assertSame(2, $status['pending']);
            $this->assertSame(2, $status['attempts']);
            $this->assertSame(1, $status['deferred']);
            $this->assertSame(1, $status['active']);
            $this->assertSame('2026-10-27T00:59:50+00:00', $status['last_attempt_at']);

            $this->assertSame(0, Artisan::call('uploadiny:cleanup-status'));
            $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($status, $output['writing_recovery']);
            $this->assertStringNotContainsString('private.png', json_encode($output, JSON_THROW_ON_ERROR));
            $this->assertStringNotContainsString('writing/deferred', json_encode($output, JSON_THROW_ON_ERROR));
            $this->assertSame($hashes, [hash('sha256', $disk->get($journal)), hash('sha256', $disk->get('writing/deferred/recovery.json'))]);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function test_deferred_writing_recovery_is_reported_then_clears_after_retry(): void
    {
        Storage::fake('local');
        $this->travelTo('2026-10-27 01:00:00');
        $disk = Storage::disk('local');
        $disk->put('writing/retry/journal.json', json_encode(['images/retry.png'], JSON_THROW_ON_ERROR));
        $disk->put('writing/retry/recovery.json', json_encode([
            'created_at' => now()->subMinutes(2)->toIso8601String(), 'attempts' => 0, 'last_attempt_at' => null, 'last_error' => null,
        ], JSON_THROW_ON_ERROR));
        $disk->put('images/retry.png', 'unowned bytes');
        $proxy = Mockery::mock($disk);
        $fails = true;
        $proxy->shouldReceive('delete')->andReturnUsing(function ($path) use (&$fails, $disk) {
            return $fails ? false : $disk->delete($path);
        });
        Storage::shouldReceive('disk')->with('local')->andReturn($proxy);

        app(PrivateFileWrites::class)->cleanupPending();

        $status = app(PrivateFileWrites::class)->recoveryStatus();
        $this->assertSame(1, $status['pending']);
        $this->assertSame(1, $status['attempts']);
        $this->assertSame(1, $status['deferred']);
        $this->assertStringNotContainsString('writing/retry', json_encode($status, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('retry.png', json_encode($status, JSON_THROW_ON_ERROR));

        $fails = false;
        app(PrivateFileWrites::class)->cleanupPending();

        $disk->assertMissing(['writing/retry/journal.json', 'writing/retry/recovery.json', 'images/retry.png']);
        $this->assertSame(['pending' => 0, 'oldest_pending_seconds' => null, 'attempts' => 0, 'deferred' => 0, 'active' => 0, 'last_attempt_at' => null], app(PrivateFileWrites::class)->recoveryStatus());
    }

    public function test_progress_sidecar_failure_does_not_fail_a_published_private_write(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $path = 'images/sidecar-failure.png';
        $proxy = Mockery::mock($disk);
        $proxy->shouldReceive('put')->andReturnUsing(function ($target, $contents) use ($disk) {
            return str_ends_with($target, '/recovery.next') ? false : $disk->put($target, $contents);
        });
        Storage::shouldReceive('disk')->with('local')->andReturn($proxy);

        $image = app(PrivateFileWrites::class)->transaction(function (PrivateFileWrites $writes) use ($disk, $path): UploadImage {
            $writes->track($path);
            $this->assertTrue($disk->put($path, 'published bytes'));

            return UploadImage::factory()->create(['path' => $path]);
        });

        $this->assertSame($path, $image->fresh()->path);
        $this->assertSame('published bytes', $disk->get($path));
        $this->assertSame([], $disk->allFiles('writing'));
    }

    public function test_completed_writing_recovery_is_removed_and_status_returns_empty(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $disk->put('writing/completed/journal.json', json_encode(['images/recover-me.png'], JSON_THROW_ON_ERROR));
        $disk->put('images/recover-me.png', 'unreferenced bytes');

        app(PrivateFileWrites::class)->cleanupPending();

        $disk->assertMissing(['writing/completed/journal.json', 'writing/completed/recovery.json', 'writing/completed/recovery.next', 'images/recover-me.png']);
        $this->assertSame(['pending' => 0, 'oldest_pending_seconds' => null, 'attempts' => 0, 'deferred' => 0, 'active' => 0, 'last_attempt_at' => null], app(PrivateFileWrites::class)->recoveryStatus());
    }
}
