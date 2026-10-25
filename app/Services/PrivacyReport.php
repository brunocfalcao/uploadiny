<?php

declare(strict_types=1);

namespace App\Services;

use App\UploadImage;
use DateTimeImmutable;
use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use JsonException;
use RuntimeException;

/**
 * Reads iPhone App Privacy Reports: newline-delimited JSON exported from
 * Settings > Privacy & Security > App Privacy Report > Save App Activity.
 *
 * Every read streams the private original with bounded line and field sizes;
 * nothing here trusts the report's content or renders it as markup.
 */
final class PrivacyReport
{
    public const EXTENSION = 'ndjson';

    /** Entries shown per page of the website's readable view. */
    public const PAGE_SIZE = 200;

    /**
     * Largest report text returned inline to an agent for one asset. Kept well below
     * common MCP client output limits (about 25k tokens) so comments and review_token survive.
     */
    public const AGENT_TEXT_LIMIT_BYTES = 49152;

    /** Content types that server-side sniffing reports for newline-delimited JSON text. */
    private const TEXT_MIME_TYPES = ['application/x-ndjson', 'application/json', 'text/plain', 'application/jsonl', 'application/x-jsonlines'];

    /** Longer lines are shown raw instead of being decoded. */
    private const MAX_DECODED_LINE_BYTES = 65536;

    private const RAW_DISPLAY_CHARACTERS = 2000;

    private const FIELD_CHARACTERS = 500;

    private const READ_BYTES = 1048576;

    private const CATEGORY_LABELS = [
        'photos' => 'Photos', 'location' => 'Location', 'contacts' => 'Contacts', 'camera' => 'Camera',
        'microphone' => 'Microphone', 'mediaLibrary' => 'Media library', 'screenRecording' => 'Screen recording',
        'calendars' => 'Calendars', 'reminders' => 'Reminders', 'bluetooth' => 'Bluetooth', 'motion' => 'Motion & fitness',
    ];

    /** True for a client `.ndjson` file whose sniffed content is text with no binary bytes. */
    public function accepts(UploadedFile $file): bool
    {
        if (strtolower($file->getClientOriginalExtension()) !== self::EXTENSION) {
            return false;
        }
        if (! in_array($file->getMimeType(), self::TEXT_MIME_TYPES, true)) {
            return false;
        }
        $path = $file->getRealPath();

        return is_string($path) && $this->isText($path);
    }

    /**
     * One page of readable entries. Blank lines are not entries; every other
     * line is counted and returned, decoded when possible and raw otherwise.
     *
     * @return array{page: int, per_page: int, total_entries: int, first_entry: int|null, last_entry: int|null, has_previous: bool, has_next: bool, entries: list<array<string, mixed>>}
     */
    public function page(UploadImage $image, int $page): array
    {
        $page = max(1, $page);
        $first = ($page - 1) * self::PAGE_SIZE;
        $handle = $this->open($image);
        $entries = [];
        $total = 0;
        $lineNumber = 0;
        try {
            foreach ($this->lines($handle) as [$line, $bytes, $truncated]) {
                $lineNumber++;
                if (trim($line) === '' && ! $truncated) {
                    continue;
                }
                if ($total >= $first && $total < $first + self::PAGE_SIZE) {
                    $entries[] = $this->entry($lineNumber, $line, $bytes, $truncated);
                }
                $total++;
            }
        } finally {
            fclose($handle);
        }

        return [
            'page' => $page,
            'per_page' => self::PAGE_SIZE,
            'total_entries' => $total,
            'first_entry' => $entries === [] ? null : $first + 1,
            'last_entry' => $entries === [] ? null : $first + count($entries),
            'has_previous' => $page > 1,
            'has_next' => $first + self::PAGE_SIZE < $total,
            'entries' => $entries,
        ];
    }

    /**
     * The report's text for an agent, cut at a line boundary when it exceeds the limit.
     *
     * @return array{text: string, truncated: bool, total_bytes: int, included_bytes: int, total_lines: int, included_lines: int}
     */
    public function agentText(UploadImage $image, int $limitBytes, int $reservedBytes = 0): array
    {
        $limitBytes = max(0, $limitBytes);
        $memoryLimit = ini_parse_quantity((string) ini_get('memory_limit'));
        $memoryLimit = $memoryLimit > 0 ? $memoryLimit : 256 * 1024 * 1024;
        // Reserve the text, its JSON escaping, and the RPC envelope, like inline images.
        $available = max(0, $memoryLimit - memory_get_usage(true) - 32 * 1024 * 1024 - $reservedBytes);
        if ($limitBytes * 6 > $available) {
            throw new RuntimeException('This report exceeds the safe inline memory budget. Its original is preserved. Download using the authenticated URL: '.route('api.images.download', $image));
        }
        $handle = $this->open($image);
        try {
            $read = $limitBytes > 0 ? (string) fread($handle, $limitBytes + 1) : '';
            $truncated = strlen($read) > $limitBytes;
            $included = $truncated ? substr($read, 0, $limitBytes) : $read;
            if ($truncated) {
                $boundary = strrpos($included, "\n");
                $included = $boundary === false ? mb_strcut($included, 0, $limitBytes, 'UTF-8') : substr($included, 0, $boundary + 1);
            }
            $totalBytes = strlen($read);
            $newlines = substr_count($read, "\n");
            $last = substr($read, -1);
            while (! feof($handle)) {
                $chunk = fread($handle, self::READ_BYTES);
                if ($chunk === false) {
                    throw new RuntimeException('The original asset file is unavailable.');
                }
                if ($chunk === '') {
                    continue;
                }
                $totalBytes += strlen($chunk);
                $newlines += substr_count($chunk, "\n");
                $last = substr($chunk, -1);
            }
        } finally {
            fclose($handle);
        }

        return [
            'text' => mb_scrub($included, 'UTF-8'),
            'truncated' => $truncated,
            'total_bytes' => $totalBytes,
            'included_bytes' => strlen($included),
            'total_lines' => $newlines + ($totalBytes > 0 && $last !== "\n" ? 1 : 0),
            'included_lines' => substr_count($included, "\n") + ($included !== '' && ! str_ends_with($included, "\n") ? 1 : 0),
        ];
    }

    /** @return resource */
    private function open(UploadImage $image): mixed
    {
        $disk = Storage::disk('local');
        $handle = $disk->exists($image->path) ? @fopen($disk->path($image->path), 'rb') : false;
        if ($handle === false) {
            throw new RuntimeException('The original asset file is unavailable.');
        }

        return $handle;
    }

    private function isText(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        try {
            while (! feof($handle)) {
                $chunk = fread($handle, self::READ_BYTES);
                if ($chunk === false || str_contains($chunk, "\0")) {
                    return false;
                }
            }
        } finally {
            fclose($handle);
        }

        return true;
    }

    /**
     * Streams physical lines, keeping at most the decodable prefix of each in memory.
     *
     * @param  resource  $handle
     * @return \Generator<int, array{string, int, bool}>
     */
    private function lines(mixed $handle): \Generator
    {
        while (true) {
            $head = '';
            $bytes = 0;
            $ending = '';
            do {
                $piece = fgets($handle, 65536);
                if ($piece === false) {
                    break;
                }
                $bytes += strlen($piece);
                $ending = substr($ending.$piece, -2);
                if (strlen($head) <= self::MAX_DECODED_LINE_BYTES) {
                    $head .= $piece;
                }
            } while (! str_ends_with($piece, "\n"));
            if ($bytes === 0) {
                return;
            }
            $length = $bytes - match (true) {
                $ending === "\r\n" => 2,
                str_ends_with($ending, "\n") => 1,
                default => 0,
            };
            $content = substr($head, 0, min(strlen($head), $length));
            $truncated = $length > self::MAX_DECODED_LINE_BYTES;

            yield [$truncated ? substr($content, 0, self::MAX_DECODED_LINE_BYTES) : $content, $length, $truncated];
            if ($piece === false) {
                return;
            }
        }
    }

    /** @return array<string, mixed> */
    private function entry(int $lineNumber, string $line, int $bytes, bool $truncated): array
    {
        $data = null;
        if (! $truncated) {
            try {
                $data = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $data = null;
            }
        }
        $base = ['line' => $lineNumber, 'kind' => 'unreadable', 'app' => null, 'accessed' => null, 'detail' => null, 'time' => null, 'raw' => null, 'raw_truncated' => false, 'raw_bytes' => null];
        if (is_array($data) && ! array_is_list($data) && ($data['type'] ?? null) === 'access') {
            $accessor = is_array($data['accessor'] ?? null) ? $data['accessor'] : [];
            $broadcaster = is_array($data['broadcaster'] ?? null) ? $this->field($data['broadcaster']['identifier'] ?? null) : null;
            $category = $this->field($data['category'] ?? null);

            return ['kind' => 'access', 'app' => $this->field($accessor['identifier'] ?? null),
                'accessed' => $category === null ? null : (self::CATEGORY_LABELS[$category] ?? $this->words($category)),
                'detail' => $this->join([match ($data['kind'] ?? null) {
                    'intervalBegin' => 'Access started', 'intervalEnd' => 'Access ended', default => $this->field($data['kind'] ?? null),
                }, $broadcaster === null ? null : 'Broadcast by '.$broadcaster]),
                'time' => $this->time($data['timeStamp'] ?? null)] + $base;
        }
        if (is_array($data) && ! array_is_list($data) && ($data['type'] ?? null) === 'networkActivity') {
            $hits = $data['hits'] ?? null;
            $first = $this->time($data['firstTimeStamp'] ?? null);
            $last = $this->time($data['timeStamp'] ?? null);

            return ['kind' => 'network', 'app' => $this->field($data['bundleID'] ?? null), 'accessed' => $this->field($data['domain'] ?? null),
                'detail' => $this->join([
                    $this->field($data['domainOwner'] ?? null),
                    is_int($hits) ? $hits.' '.($hits === 1 ? 'contact' : 'contacts').' in 7 days' : null,
                    match ($data['initiatedType'] ?? null) {
                        'AppInitiated' => 'App initiated', 'NonAppInitiated' => 'Not app initiated', default => $this->field($data['initiatedType'] ?? null)
                    },
                    $this->field($data['context'] ?? null) === null ? null : 'Context: '.$this->field($data['context']),
                    $first !== null && $first !== $last ? 'First contact '.$first : null,
                ]),
                'time' => $last] + $base;
        }
        $raw = mb_scrub($line, 'UTF-8');
        $shown = mb_substr($raw, 0, self::RAW_DISPLAY_CHARACTERS, 'UTF-8');
        $other = is_array($data) && ($data === [] || ! array_is_list($data));

        return ['kind' => $other ? 'other' : 'unreadable',
            'app' => $other ? $this->field($data['bundleID'] ?? (is_array($data['accessor'] ?? null) ? $data['accessor']['identifier'] ?? null : null)) : null,
            'accessed' => $other ? $this->field($data['type'] ?? null) : null,
            'time' => $other ? $this->time($data['timeStamp'] ?? null) : null,
            'raw' => $shown, 'raw_truncated' => $truncated || $shown !== $raw, 'raw_bytes' => $bytes] + $base;
    }

    private function field(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (! is_string($value)) {
            return null;
        }
        $value = trim(mb_scrub($value, 'UTF-8'));
        if ($value === '') {
            return null;
        }

        return mb_strlen($value, 'UTF-8') > self::FIELD_CHARACTERS ? mb_substr($value, 0, self::FIELD_CHARACTERS, 'UTF-8').'…' : $value;
    }

    private function time(mixed $value): ?string
    {
        $value = $this->field($value);
        if ($value === null || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,9})?(Z|[+-]\d{2}:?\d{2})$/D', $value) !== 1) {
            return $value;
        }
        try {
            $time = new DateTimeImmutable($value);
        } catch (Exception) {
            return $value;
        }

        return $time->format('j M Y, H:i:s').' '.($time->getOffset() === 0 ? 'UTC' : $time->format('P'));
    }

    private function words(string $value): string
    {
        return ucfirst(strtolower((string) preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $value)));
    }

    /** @param list<string|null> $parts */
    private function join(array $parts): ?string
    {
        $parts = array_values(array_filter($parts, static fn (?string $part): bool => $part !== null && $part !== ''));

        return $parts === [] ? null : implode(' · ', $parts);
    }
}
