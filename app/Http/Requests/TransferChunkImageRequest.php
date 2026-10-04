<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TransferChunkImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'chunk_id' => ['required', 'uuid', 'exists:upload_chunks,uuid'],
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'action' => ['required', 'in:copy,move'],
        ];
    }
}
