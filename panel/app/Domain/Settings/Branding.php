<?php

declare(strict_types=1);

namespace App\Domain\Settings;

use App\Support\Images\ImageMetadata;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * The panel's name, colour, logo, favicon and support contacts, set on the
 * Branding page of Panel settings (docs/adr/0053). Nothing company-specific
 * is built in: without settings the panel is called APP_NAME and looks like
 * Proxmotion.
 */
final class Branding
{
    /**
     * The images that can be uploaded and the setting holding each one's file name.
     */
    public const array IMAGES = [
        'logo' => 'brand_logo',
        'logo_dark' => 'brand_logo_dark',
        'favicon' => 'brand_favicon',
    ];

    /** The accepted image types and their file extensions; never SVG, which can hold scripts. */
    public const array TYPES = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    /** Where the images are kept on the local disk. */
    public const string DIRECTORY = 'branding';

    /** The names files are stored under: random, so a new upload is a new address. */
    public const string FILE_PATTERN = '[A-Za-z0-9]{40}\.(png|jpg|webp)';

    private const string LOCK = 'branding-images';

    public static function name(): string
    {
        $name = PanelSettings::get('brand_name');

        return is_string($name) && $name !== '' ? $name : (string) config('app.name');
    }

    /**
     * The colour as #rrggbb, or null for the default theme.
     */
    public static function color(): ?string
    {
        $color = PanelSettings::get('brand_color');

        // The form saves lowercase; a default from .env may be uppercase.
        return is_string($color) && preg_match('/^#[0-9a-f]{6}$/i', $color) === 1 ? strtolower($color) : null;
    }

    /**
     * The support address, when it is one; a default from .env is not
     * checked by the form.
     */
    public static function supportEmail(): ?string
    {
        $email = PanelSettings::get('support_email');

        return is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }

    /**
     * The support page, only over HTTPS like the form requires: it is a link
     * on the customer console.
     */
    public static function supportUrl(): ?string
    {
        $url = PanelSettings::get('support_url');

        return is_string($url) && filter_var($url, FILTER_VALIDATE_URL) !== false && str_starts_with(strtolower($url), 'https://') ? $url : null;
    }

    /**
     * Black or white, whichever is easier to read on the colour.
     */
    public static function foreground(string $color): string
    {
        $luminance = self::luminance($color);

        // Contrast against white is 1.05 / (L + 0.05), against black (L + 0.05) / 0.05.
        return 1.05 / ($luminance + 0.05) >= ($luminance + 0.05) / 0.05 ? '#ffffff' : '#000000';
    }

    /**
     * Relative luminance of a #rrggbb colour (WCAG 2).
     */
    public static function luminance(string $color): float
    {
        $channels = array_map(function (string $hex): float {
            $value = hexdec($hex) / 255;

            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, str_split(substr($color, 1), 2));

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    /**
     * The address of an uploaded image, or null when none is uploaded.
     */
    public static function imageUrl(string $image): ?string
    {
        $file = self::file($image);

        return $file === null ? null : route('branding.image', $file);
    }

    /**
     * Whether the file is one of the images in use, the only ones served.
     */
    public static function serves(string $file): bool
    {
        return in_array($file, array_map(self::file(...), array_keys(self::IMAGES)), true);
    }

    /**
     * Stores an uploaded image without its metadata in place of the current
     * one, which is deleted. The type was checked by the request, from the
     * file's content.
     *
     * @throws BrandingImageNotStored
     */
    public static function store(string $image, UploadedFile $upload): void
    {
        $type = (string) $upload->getMimeType();
        $bytes = ImageMetadata::strip((string) $upload->get(), $type)
            ?? throw new BrandingImageNotStored('The image could not be read. Save it again as PNG, JPEG or WebP and upload it.');
        $file = Str::random(40).'.'.self::TYPES[$type];
        $path = self::DIRECTORY.'/'.$file;

        if (! Storage::disk('local')->put($path, $bytes)) {
            throw new BrandingImageNotStored('The image could not be stored. Check that storage/ is writable.');
        }

        try {
            self::replace($image, $file);
        } catch (Throwable $e) {
            Storage::disk('local')->delete($path);

            throw $e;
        }
    }

    public static function remove(string $image): void
    {
        self::replace($image, null);
    }

    /**
     * What every page needs: shared with Inertia and read by the root view.
     *
     * @return array{name: string, color: string|null, color_foreground: string|null, logo_url: string|null, logo_dark_url: string|null, favicon_url: string|null, support_email: string|null, support_url: string|null}
     */
    public static function forPages(): array
    {
        $color = self::color();

        return [
            'name' => self::name(),
            'color' => $color,
            'color_foreground' => $color === null ? null : self::foreground($color),
            'logo_url' => self::imageUrl('logo'),
            'logo_dark_url' => self::imageUrl('logo_dark'),
            'favicon_url' => self::imageUrl('favicon'),
            'support_email' => self::supportEmail(),
            'support_url' => self::supportUrl(),
        ];
    }

    private static function file(string $image): ?string
    {
        $file = PanelSettings::get(self::IMAGES[$image]);

        return is_string($file) && preg_match('/^'.self::FILE_PATTERN.'$/', $file) === 1 ? $file : null;
    }

    /**
     * One change at a time, so two uploads at once never both delete the
     * same old file and leave one of the new ones unreferenced.
     *
     * @throws BrandingImageNotStored
     */
    private static function replace(string $image, ?string $file): void
    {
        try {
            Cache::lock(self::LOCK, 30)->block(10, function () use ($image, $file): void {
                $old = self::file($image);

                PanelSettings::save([self::IMAGES[$image] => $file]);

                if ($old !== null && $old !== $file) {
                    Storage::disk('local')->delete(self::DIRECTORY.'/'.$old);
                }
            });
        } catch (LockTimeoutException) {
            throw new BrandingImageNotStored('Another change of the images is still running. Try again.');
        }
    }
}
