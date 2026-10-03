<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ChunkUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['files' => ['required', 'array', 'min:1'], 'files.*' => ['required', 'file', 'max:512000', 'mimes:jpg,jpeg,png,gif,webp,bmp']];
    }
}
