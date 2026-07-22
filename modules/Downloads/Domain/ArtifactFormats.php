<?php

namespace Modules\Downloads\Domain;

/**
 * What an artifact is allowed to be, shared by every route that creates one.
 *
 * Lives here rather than on a FormRequest because there is now more than one way
 * a file becomes an artifact — a browser upload and a server-side import — and
 * the two must not be able to disagree about what is acceptable. A file dropped
 * into the incoming directory by hand has had *less* scrutiny than an uploaded
 * one, not more, so it gets the same checks.
 */
final class ArtifactFormats
{
    /**
     * Distributable formats only.
     *
     * The gap this closes is not "wrong file type" — it is that the endpoint
     * accepted *anything* and the Download Center then served it from the
     * platform's own origin. An `.html` or `.svg` artifact is script running as
     * this site, and nothing downstream would object: the content type is
     * detected and recorded, never enforced.
     *
     * Extensions rather than MIME types, because these are opaque binaries: an
     * APK and a JAR are both detected as `application/zip`, so a MIME allowlist
     * wide enough to admit them admits far more than intended.
     *
     * @var list<string>
     */
    public const EXTENSIONS = [
        // Mobile
        'apk', 'aab', 'ipa',
        // Desktop installers
        'exe', 'msi', 'msix', 'dmg', 'pkg', 'deb', 'rpm', 'appimage',
        // Archives
        'zip', 'tar', 'gz', 'tgz', 'xz', '7z',
        // Runtime bundles
        'jar', 'whl',
        // Firmware and disk images — the IoT/smart-controller products ship
        // these, and they are inert in a browser, which is what the list guards.
        'bin', 'hex', 'img',
    ];

    /**
     * Builds of the same platform that are not interchangeable.
     *
     * Constrained to a known set because the value ends up in a public URL and,
     * for Android, is matched *exactly* against the ABI a device reports — a
     * typo'd variant is not a validation nicety, it is a download no device can
     * find. Omitting it means universal: one build that installs anywhere.
     *
     * @var list<string>
     */
    public const VARIANTS = [
        // Android ABIs, as `Build.SUPPORTED_ABIS` reports them.
        'arm64-v8a', 'armeabi-v7a', 'x86_64', 'x86',
        // Desktop architectures.
        'arm64', 'x64',
    ];

    public static function allows(string $filename): bool
    {
        return in_array(self::extensionOf($filename), self::EXTENSIONS, true);
    }

    public static function extensionOf(string $filename): string
    {
        return mb_strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    }

    /** The rejection message, phrased the same way whichever route rejected. */
    public static function rejectionFor(string $filename): string
    {
        $extension = self::extensionOf($filename);

        return $extension === ''
            ? 'The file needs an extension so its type can be checked.'
            : "\".{$extension}\" files cannot be uploaded as artifacts. Allowed: ".implode(', ', self::EXTENSIONS).'.';
    }
}
