<?php

declare(strict_types=1);

namespace App\Services;

final class ImageOrientation
{
    /** @param mixed $image A GD image when the GD extension is available. */
    public function apply(mixed $image, string $path): mixed
    {
        return match ($this->read($path)) {
            2 => $this->flip($image, IMG_FLIP_HORIZONTAL),
            3 => $this->rotate($image, 180),
            4 => $this->flip($image, IMG_FLIP_VERTICAL),
            5 => $this->rotate($this->flip($image, IMG_FLIP_HORIZONTAL), 90),
            6 => $this->rotate($image, 270),
            7 => $this->rotate($this->flip($image, IMG_FLIP_HORIZONTAL), 270),
            8 => $this->rotate($image, 90),
            default => $image,
        };
    }

    public function read(string $path): int
    {
        if (! function_exists('exif_read_data')) {
            return 1;
        }
        $metadata = @exif_read_data($path, null, true, false);
        $orientation = $metadata['IFD0']['Orientation'] ?? $metadata['Orientation'] ?? 1;

        return is_numeric($orientation) && (int) $orientation >= 1 && (int) $orientation <= 8 ? (int) $orientation : 1;
    }

    private function flip(mixed $image, int $mode): mixed
    {
        imageflip($image, $mode);

        return $image;
    }

    private function rotate(mixed $image, int $degrees): mixed
    {
        $rotated = imagerotate($image, $degrees, 0);

        return $rotated === false ? $image : $rotated;
    }
}
