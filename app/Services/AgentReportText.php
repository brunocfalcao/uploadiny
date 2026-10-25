<?php

declare(strict_types=1);

namespace App\Services;

use App\UploadImage;
use RuntimeException;

/** Inline App Privacy Report text for MCP responses, with an explicit truncation note. */
final class AgentReportText
{
    public function __construct(private PrivacyReport $reports) {}

    /**
     * @return array{note: string, text: string|null, bytes: int}
     */
    public function read(UploadImage $image, int $limitBytes, int $reservedBytes = 0): array
    {
        $download = route('api.images.download', $image);
        $subject = 'App Privacy Report '.$image->name.' (asset '.$image->uuid.')';
        if ($limitBytes <= 0) {
            return ['note' => $subject.' text was not included because this response already carries the maximum inline report text. Call get_asset with this asset ID, or download the complete original with the authenticated URL: '.$download, 'text' => null, 'bytes' => 0];
        }
        try {
            $report = $this->reports->agentText($image, $limitBytes, $reservedBytes);
        } catch (RuntimeException $error) {
            return ['note' => $subject.' text is unavailable: '.$error->getMessage(), 'text' => null, 'bytes' => 0];
        }
        $size = $this->number($report['total_bytes']).' bytes, '.$this->number($report['total_lines']).' '.($report['total_lines'] === 1 ? 'line' : 'lines');
        if (! $report['truncated']) {
            return ['note' => $subject.', complete text follows ('.$size.').', 'text' => $report['text'], 'bytes' => strlen($report['text'])];
        }
        $omittedBytes = $report['total_bytes'] - $report['included_bytes'];
        $omittedLines = max(0, $report['total_lines'] - $report['included_lines']);

        return [
            'note' => $subject.' is TRUNCATED. The text that follows contains only the first '.$this->number($report['included_bytes']).' of '.$this->number($report['total_bytes']).' bytes (lines 1-'.$this->number($report['included_lines']).' of '.$this->number($report['total_lines']).'). '
                .$this->number($omittedBytes).' bytes and '.$this->number($omittedLines).' '.($omittedLines === 1 ? 'line were' : 'lines were').' omitted. Download the complete original with the authenticated URL: '.$download,
            'text' => $report['text'],
            'bytes' => strlen($report['text']),
        ];
    }

    private function number(int $value): string
    {
        return number_format($value, 0, '.', ',');
    }
}
