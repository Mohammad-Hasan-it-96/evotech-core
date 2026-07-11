<?php

namespace Modules\Downloads\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\Rule;
use Modules\Downloads\Domain\Enums\Platform;

class UploadArtifactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:'.Config::integer('downloads.max_upload_kilobytes')],
            'platform' => ['required', Rule::enum(Platform::class)],
        ];
    }
}
