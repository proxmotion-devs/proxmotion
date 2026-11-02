<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Settings\Branding;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves the uploaded logos and favicon (docs/adr/0053) to everyone, the
 * sign-in pages and the customer console included. Only the images in use
 * are served; a new upload gets a new name, so they are cached for a year.
 */
class BrandingFileController extends Controller
{
    public function __invoke(string $file): StreamedResponse
    {
        $path = Branding::DIRECTORY.'/'.$file;

        abort_unless(Branding::serves($file) && Storage::disk('local')->exists($path), 404);

        $extension = pathinfo($file, PATHINFO_EXTENSION);

        return Storage::disk('local')->response($path, $file, [
            'Content-Type' => (string) array_search($extension, Branding::TYPES, true),
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ], 'inline');
    }
}
