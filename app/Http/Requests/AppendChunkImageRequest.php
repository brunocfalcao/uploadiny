<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\PrivacyReport;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class AppendChunkImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:97280', $this->sharesPrivacyReport() ? $this->privacyReportRule() : 'mimes:jpg,jpeg,png,gif,webp,bmp,mp4,mov,m4v'],
            'comments' => ['nullable', 'string', 'max:50000'],
        ];
    }

    /** Only the iPhone Share Extension's upload path accepts App Privacy Reports. */
    private function sharesPrivacyReport(): bool
    {
        $file = $this->file('file');

        return $this->routeIs('api.chunks.append') && $file instanceof UploadedFile
            && strtolower($file->getClientOriginalExtension()) === PrivacyReport::EXTENSION;
    }

    private function privacyReportRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if ($value instanceof UploadedFile && ! app(PrivacyReport::class)->accepts($value)) {
                $fail('The App Privacy Report must be the text .ndjson file saved from iPhone Settings.');
            }
        };
    }
}
