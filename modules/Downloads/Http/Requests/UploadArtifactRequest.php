<?php

namespace Modules\Downloads\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Downloads\Domain\ArtifactFormats;
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
            'variant' => ['sometimes', 'nullable', 'string', Rule::in(ArtifactFormats::VARIANTS)],
        ];
    }

    /** Empty string is the storage form of "universal", never null. */
    public function variant(): string
    {
        $variant = $this->input('variant');

        return is_string($variant) ? trim($variant) : '';
    }

    /**
     * The extension check is here rather than in a `mimes:` rule because `mimes`
     * maps an extension to expected MIME types and verifies the detected type
     * matches — which rejects legitimate APKs (detected as `application/zip`)
     * while still admitting anything sharing that mapping.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $file = $this->file('file');

            if (! $file instanceof UploadedFile) {
                return;
            }

            $name = $file->getClientOriginalName();

            if (! ArtifactFormats::allows($name)) {
                $validator->errors()->add('file', ArtifactFormats::rejectionFor($name));
            }
        });
    }
}
