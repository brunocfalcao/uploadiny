<?php

declare(strict_types=1);

namespace App\Services;

use App\UploadImage;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class VisionDescription
{
    public function __construct(private ImageOrientation $orientation) {}

    public function describe(UploadImage $image): string
    {
        $binary = config('services.uploadiny.vision_binary');
        if (! is_string($binary) || $binary === '') {
            throw new VisionFailure('missing_configuration', 'Vision is not configured yet.');
        }
        $path = Storage::disk('local')->path($image->path);
        $dimensions = @getimagesize($path);
        if (! $dimensions || $dimensions[0] * $dimensions[1] > 24000000) {
            throw new VisionFailure('unsupported_image', 'This image cannot be prepared for a vision description.');
        }
        $ratio = min(1, 1536 / max($dimensions[0], $dimensions[1]));
        $width = max(1, (int) round($dimensions[0] * $ratio));
        $height = max(1, (int) round($dimensions[1] * $ratio));
        $limit = ini_parse_quantity((string) ini_get('memory_limit'));
        $limit = $limit > 0 ? $limit : 256 * 1024 * 1024;
        // GD needs the decoded source and preview together; rotation only retains bounded canvases.
        $available = max(0, $limit - memory_get_usage(true) - 16 * 1024 * 1024);
        if (max($dimensions[0] * $dimensions[1] * 4 + $width * $height * 4, $width * $height * 9) > $available) {
            throw new VisionFailure('unsupported_image', 'This image cannot be prepared for a vision description.');
        }
        $source = match ($image->mime_type) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
            'image/gif' => @imagecreatefromgif($path),
            'image/bmp', 'image/x-ms-bmp' => @imagecreatefrombmp($path),
            default => false,
        };
        if (! $source) {
            throw new VisionFailure('unsupported_format', 'Vision cannot read this image format.');
        }
        $preview = imagecreatetruecolor($width, $height);
        imagefill($preview, 0, 0, imagecolorallocate($preview, 255, 255, 255));
        imagecopyresampled($preview, $source, 0, 0, 0, 0, imagesx($preview), imagesy($preview), imagesx($source), imagesy($source));
        unset($source);
        $preview = $this->orientation->apply($preview, $path);
        ob_start();
        imagejpeg($preview, null, 85);
        $bytes = ob_get_clean();
        unset($preview, $source);
        $response = Process::input($bytes)->timeout((int) config('services.uploadiny.vision_timeout'))->run([
            '/usr/bin/sudo', '-n', '-H', '-u', 'olloma-agent', $binary,
        ]);
        if (! $response->successful()) {
            throw new VisionFailure('provider_refusal', 'The vision provider could not describe this image. Try again later.');
        }
        $result = json_decode($response->output(), true);
        $description = is_array($result) ? ($result['description'] ?? null) : null;
        if (! is_string($description) || trim($description) === '' || ($result['model'] ?? null) !== config('services.uploadiny.vision_model')) {
            throw new VisionFailure('malformed_response', 'The vision provider returned an invalid description.');
        }

        return trim($description);
    }
}
