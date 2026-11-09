<?php

declare(strict_types=1);

namespace App\Services {
    function fread($handle, int $length): string|false
    {
        $value = \fread($handle, $length);
        $GLOBALS['report_read_bytes'] += strlen($value ?: '');

        return $value;
    }

    function fgets($handle, int $length): string|false
    {
        $value = \fgets($handle, $length);
        $GLOBALS['report_read_bytes'] += strlen($value ?: '');

        return $value;
    }
}

namespace {
    use App\Services\PrivacyReport;
    use App\UploadImage;
    use Illuminate\Contracts\Console\Kernel;

    require dirname(__DIR__, 2).'/vendor/autoload.php';
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    config(['cache.default' => 'array', 'filesystems.disks.local.root' => dirname($argv[1])]);
    $image = new UploadImage(['path' => basename($argv[1]), 'mime_type' => 'application/x-ndjson']);
    $report = app(PrivacyReport::class);
    $output = [];
    foreach (['agentText' => 49152, 'page' => 1] as $method => $argument) {
        $GLOBALS['report_read_bytes'] = 0;
        $first = $report->$method($image, $argument);
        $cold = $GLOBALS['report_read_bytes'];
        $GLOBALS['report_read_bytes'] = 0;
        $second = $report->$method($image, $argument);
        $output[$method] = ['cold' => $cold, 'warm' => $GLOBALS['report_read_bytes'], 'same' => $first === $second];
    }
    file_put_contents($argv[1].'.next', "replacement\n");
    rename($argv[1].'.next', $argv[1]);
    $output['replaced'] = $report->agentText($image, 49152);
    $output['replaced_entries'] = $report->page($image, 1)['total_entries'];
    unlink($argv[1]);
    try {
        $report->agentText($image, 49152);
        $output['deleted'] = false;
    } catch (RuntimeException) {
        $output['deleted'] = true;
    }
    echo json_encode($output, JSON_THROW_ON_ERROR);
}
