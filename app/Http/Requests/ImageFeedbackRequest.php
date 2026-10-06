<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImageFeedbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'comments' => ['present', 'nullable', 'string', 'max:50000'],
            'annotations' => ['present', 'array', 'max:1000'],
            'annotations.*.tool' => ['required', 'in:pen,arrow,line,rectangle,ellipse,callout'],
            'annotations.*.text' => ['present_if:annotations.*.tool,callout', 'nullable', 'string', 'max:50000'],
            'annotations.*.points.2' => ['required_if:annotations.*.tool,callout', 'array'],
            'annotations.*.points.3' => ['required_if:annotations.*.tool,callout', 'array'],
            'annotations.*.color' => ['required', 'regex:/^#[0-9a-fA-F]{6}\z/'],
            'annotations.*.width' => ['required', 'numeric', 'min:0.0001', 'max:0.1'],
            'annotations.*.points' => ['required', 'array', 'min:2', 'max:10000'],
            'annotations.*.points.*.x' => ['required', 'numeric', 'between:0,1'],
            'annotations.*.points.*.y' => ['required', 'numeric', 'between:0,1'],
            'annotated_image' => ['nullable', 'string', 'max:30000000'],
            'revision' => ['required', 'integer', 'min:0'],
        ];
    }
}
