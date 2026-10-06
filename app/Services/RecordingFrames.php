<?php

declare(strict_types=1);

namespace App\Services;

use App\UploadImage;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class RecordingFrames
{
    /** @param list<float> $timestamps
     * @return list<array{timestamp_seconds: float, data: string}>
     */
    public function extract(UploadImage $image, array $timestamps): array
    {
        $path = Storage::disk('local')->path($image->path);
        $frames = [];
        foreach ($timestamps as $timestamp) {
            $result = Process::timeout(5)->run([
                config('services.uploadiny.ffmpeg_binary'), '-nostdin', '-v', 'error',
                '-protocol_whitelist', 'file,pipe', '-ss', (string) $timestamp,
                '-threads', '1', '-i', $path, '-frames:v', '1',
                '-vf', "scale=w='min(1280,iw)':h='min(1280,ih)':force_original_aspect_ratio=decrease",
                '-threads', '1', '-f', 'image2pipe', '-vcodec', 'mjpeg', 'pipe:1',
            ]);
            if (! $result->successful() || $result->output() === '') {
                throw new RuntimeException('Recording frame extraction failed.', $result->exitCode());
            }
            $frames[] = ['timestamp_seconds' => $timestamp, 'data' => $result->output()];
        }

        return $frames;
    }
}
