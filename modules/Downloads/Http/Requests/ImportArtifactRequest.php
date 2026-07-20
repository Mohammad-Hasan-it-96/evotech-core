<?php

namespace Modules\Downloads\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Downloads\Domain\ArtifactFormats;
use Modules\Downloads\Domain\Enums\Platform;

/**
 * Register a build already staged on the server.
 *
 * The file arrived outside the application — SFTP, the control panel's file
 * manager — so none of the guarantees an upload carries apply here. It has not
 * been size-checked, its name was chosen by whoever put it there, and it may not
 * be in the incoming directory at all. Everything is re-established from scratch.
 */
class ImportArtifactRequest extends FormRequest
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
            'filename' => ['required', 'string', 'max:255'],
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
     * Only the file's own name, never a path.
     *
     * The client has no business naming a directory, and stripping it here means
     * no caller downstream can be tricked into resolving one — an unfiltered
     * `../../.env` would otherwise become a publicly downloadable artifact.
     */
    public function filename(): string
    {
        return basename((string) $this->string('filename'));
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $filename = $this->filename();

            if ($filename !== '' && ! ArtifactFormats::allows($filename)) {
                $validator->errors()->add('filename', ArtifactFormats::rejectionFor($filename));
            }
        });
    }
}
