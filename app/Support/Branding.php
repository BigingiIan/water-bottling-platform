<?php

namespace App\Support;

use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * The organisation's name and logo as shown to people who are not signed in. Only these two values are
 * public; nothing else about the business is exposed.
 *
 * The logo is kept on a private disk (filesystems.branding_disk, the local disk by default) and served by
 * the branding.logo route, so it works without storage:link and only that one file is reachable.
 */
class Branding
{
    public const DIRECTORY = 'branding';

    public function __construct(private SettingsService $settings) {}

    /**
     * The disk the logo lives on; object storage on hosts whose local files do not survive a deploy.
     */
    public function diskName(): string
    {
        $disk = config('filesystems.branding_disk');

        return is_string($disk) && $disk !== '' ? $disk : 'local';
    }

    public function organisationName(): string
    {
        return app(FooterDetails::class)->organisationName();
    }

    public function logoPath(): ?string
    {
        try {
            $path = $this->settings->get('organisation.logo');
        } catch (Throwable) {
            return null;
        }

        return is_string($path) && $path !== '' && Storage::disk($this->diskName())->exists($path) ? $path : null;
    }

    /**
     * Versioned by file name, so a new upload is never hidden by the long browser cache.
     */
    public function logoUrl(): ?string
    {
        $path = $this->logoPath();

        return $path === null ? null : route('branding.logo', ['v' => substr(sha1($path), 0, 10)]);
    }

    public function storeLogo(UploadedFile $file, User $user): void
    {
        Impersonation::ensureWritable();

        $previous = $this->logoPath();
        $path = $file->storeAs(self::DIRECTORY, 'logo-'.Str::lower(Str::random(16)).'.'.$file->extension(), $this->diskName());

        $this->settings->set('organisation.logo', $path, $user);

        if ($previous !== null && $previous !== $path) {
            Storage::disk($this->diskName())->delete($previous);
        }
    }

    public function removeLogo(User $user): void
    {
        Impersonation::ensureWritable();

        $previous = $this->logoPath();
        $this->settings->set('organisation.logo', '', $user);

        if ($previous !== null) {
            Storage::disk($this->diskName())->delete($previous);
        }
    }
}
