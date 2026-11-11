<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Process;

class CommentCorrection
{
    /**
     * Return the corrected feedback text from the OpenClaw text bridge, or throw VisionFailure
     * (fail closed) when the bridge is unavailable, errors, or returns an implausible correction.
     */
    public function correct(string $text, ?string $projectName, ?string $projectDescription): string
    {
        $binary = config('services.uploadiny.comment_correction_binary');
        if (! is_string($binary) || $binary === '') {
            throw new VisionFailure('missing_configuration', 'Comment correction is not configured yet.');
        }
        $input = json_encode(['feedback' => $text, 'project' => ['name' => $projectName ?? '', 'description' => $projectDescription]], JSON_THROW_ON_ERROR);
        $response = Process::input($input)->timeout((int) config('services.uploadiny.comment_correction_timeout'))->run([
            '/usr/bin/sudo', '-n', '-H', '-u', 'olloma-agent', $binary,
        ]);
        if (! $response->successful()) {
            throw new VisionFailure('provider_refusal', 'The comment correction provider could not process this text.');
        }
        $result = json_decode($response->output(), true);
        $corrected = is_array($result) ? ($result['text'] ?? null) : null;
        if (! is_string($corrected) || trim($corrected) === '' || ($result['model'] ?? null) !== config('services.uploadiny.comment_correction_model')) {
            throw new VisionFailure('malformed_response', 'The comment correction provider returned an invalid result.');
        }
        $corrected = trim($corrected);
        if (preg_match('/[^\P{Cc}\r\n\t]/u', $corrected) === 1) {
            throw new VisionFailure('malformed_response', 'The comment correction provider returned an invalid result.');
        }
        $length = mb_strlen($text);
        if (abs(mb_strlen($corrected) - $length) > max(20, (int) ceil($length * 0.2))) {
            throw new VisionFailure('implausible_correction', 'The comment correction changed the text too much.');
        }

        return $corrected;
    }
}
