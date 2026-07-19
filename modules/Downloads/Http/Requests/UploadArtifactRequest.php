<?php

namespace Modules\Downloads\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Downloads\Domain\Enums\Platform;

class UploadArtifactRequest extends FormRequest
{
    /**
     * Distributable formats only.
     *
     * The gap this closes is not "wrong file type" — it is that the endpoint
     * accepted *anything* up to 2 GB and the Download Center then served it from
     * the platform's own origin. An `.html` or `.svg` artifact is script running as
     * this site, and nothing downstream would have objected: the content type is
     * detected and recorded, never enforced.
     *
     * Extensions rather than MIME types, because these are opaque binaries: an APK
     * and a JAR are both detected as `application/zip`, so a MIME allowlist wide
     * enough to admit them admits far more than intended.
     */
    private const ALLOWED_EXTENSIONS = [
        // Mobile
        'apk', 'aab', 'ipa',
        // Desktop installers
        'exe', 'msi', 'msix', 'dmg', 'pkg', 'deb', 'rpm', 'appimage',
        // Archives
        'zip', 'tar', 'gz', 'tgz', 'xz', '7z',
        // Runtime bundles
        'jar', 'whl',
        // Firmware and disk images — the IoT/smart-controller products ship these,
        // and they are inert in a browser, which is what the list is guarding.
        'bin', 'hex', 'img',
    ];

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

            $extension = mb_strtolower($file->getClientOriginalExtension());

            if (in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
                return;
            }

            $validator->errors()->add(
                'file',
                $extension === ''
                    ? 'The file needs an extension so its type can be checked.'
                    : "\".{$extension}\" files cannot be uploaded as artifacts. Allowed: ".implode(', ', self::ALLOWED_EXTENSIONS).'.',
            );
        });
    }
}
