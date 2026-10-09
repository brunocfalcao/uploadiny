<?php

declare(strict_types=1);

namespace App\Services;

use App\UploadImage;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class AgentImageContent
{
    /** @return array{data: string, mime_type: string} */
    public function read(UploadImage $image, string $variant, ?int $width = null, int $reservedBytes = 0): array
    {
        $path = $variant === 'annotated' ? $image->annotated_path : $image->path;
        $disk = Storage::disk('local');
        if ($path === null || ! $disk->exists($path)) {
            throw new RuntimeException($variant === 'annotated' ? 'No annotated image is available for this asset. Try original.' : 'The original asset file is unavailable.');
        }
        $size = $disk->size($path);
        if ($size > 250 * 1024 * 1024) {
            throw new RuntimeException('This image is larger than 250 MB and cannot be sent to the agent. Ask the user to share a smaller export.');
        }

        // Reserve raw bytes, base64, JSON, and the final RPC envelope for every image.
        $limit = ini_parse_quantity((string) ini_get('memory_limit'));
        $limit = $limit > 0 ? $limit : 256 * 1024 * 1024;
        $available = max(0, $limit - memory_get_usage(true) - 32 * 1024 * 1024 - $reservedBytes);
        $download = route($variant === 'annotated' ? 'api.images.annotated' : 'api.images.download', $image);
        $budgetError = 'This image exceeds the safe inline memory budget. Its original is preserved. Download using the authenticated URL: '.$download;
        if ($size > intdiv($available, 6)) {
            throw new RuntimeException($budgetError);
        }

        $mime = $variant === 'annotated' ? 'image/png' : $image->mime_type;
        if ($width === null) {
            return ['data' => $disk->get($path), 'mime_type' => $mime];
        }
        $absolutePath = $disk->path($path);
        $dimensions = @getimagesize($absolutePath);
        if ($dimensions === false) {
            throw new RuntimeException('This image cannot be resized. Request it without image_width or use its authenticated download URL: '.$download);
        }
        if ($dimensions[0] <= $width) {
            return ['data' => $disk->get($path), 'mime_type' => $mime];
        }
        if (! extension_loaded('gd')) {
            throw new RuntimeException('Image resizing is unavailable. Request it without image_width.');
        }
        $height = max(1, (int) round($dimensions[1] * $width / $dimensions[0]));
        // Decoding peaks before the destination exists. PNG encoding needs both
        // canvases, but GD is released before base64 and JSON are built.
        $pixels = $width * $height;
        $sourcePixels = $dimensions[0] * $dimensions[1];
        if (max($sourcePixels * 8, $sourcePixels * 4 + $pixels * 9) > $available) {
            throw new RuntimeException($budgetError);
        }
        $source = match ($dimensions[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($absolutePath),
            IMAGETYPE_PNG => @imagecreatefrompng($absolutePath),
            IMAGETYPE_WEBP => @imagecreatefromwebp($absolutePath),
            IMAGETYPE_GIF => @imagecreatefromgif($absolutePath),
            IMAGETYPE_BMP => @imagecreatefrombmp($absolutePath),
            default => false,
        };
        if ($source === false) {
            throw new RuntimeException('This image format cannot be resized. Request it without image_width.');
        }
        $preview = imagecreatetruecolor($width, $height);
        try {
            imagealphablending($preview, false);
            imagesavealpha($preview, true);
            imagecopyresampled($preview, $source, 0, 0, 0, 0, $width, $height, $dimensions[0], $dimensions[1]);
            ob_start();
            imagepng($preview);
            $bytes = (string) ob_get_clean();
        } finally {
            unset($preview, $source);
        }
        if (strlen($bytes) > intdiv($available, 6)) {
            throw new RuntimeException($budgetError);
        }

        return ['data' => $bytes, 'mime_type' => 'image/png'];
    }
}
