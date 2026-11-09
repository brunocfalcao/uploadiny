<?php

declare(strict_types=1);
use App\Services\VisionDescription;
use App\Services\VisionFailure;
use App\UploadImage;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Process;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['filesystems.disks.local.root' => dirname($argv[1])]);
Process::fake(['*' => Process::result(output: '{"description":"Memory preview","model":"gpt-6-luna"}')]);
$image = new UploadImage(['path' => basename($argv[1]), 'mime_type' => 'image/jpeg']);
try {
    $result = app(VisionDescription::class)->describe($image);
    Process::assertRan(function ($process): bool {
        $image = imagecreatefromstring($process->input);
        echo json_encode(['width' => imagesx($image), 'height' => imagesy($image), 'corner' => imagecolorsforindex($image, imagecolorat($image, 10, 10))]);

        return true;
    });

} catch (VisionFailure $error) {
    echo json_encode(['failure' => $error->category]);
}
