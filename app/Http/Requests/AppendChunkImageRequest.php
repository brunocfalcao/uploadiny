<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'file' => ['required', 'file', 'max:97280', 'mimes:jpg,jpeg,png,gif,webp,bmp,mp4,mov,m4v'],
            'comments' => ['nullable', 'string', 'max:50000'],
        ];
    }
}
