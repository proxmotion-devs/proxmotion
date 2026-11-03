<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Settings\Branding;
use App\Domain\Settings\BrandingImageNotStored;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BrandingImageRequest;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * Uploading and removing the logo, the dark-mode logo and the favicon on the
 * Branding page of Panel settings (docs/adr/0053). A failure is shown as an
 * error of the file, next to the image.
 */
class BrandingImageController extends Controller
{
    public function store(BrandingImageRequest $request, #[CurrentUser] User $user, string $image): RedirectResponse
    {
        /** @var UploadedFile $file */
        $file = $request->file('file');

        try {
            Branding::store($image, $file);
        } catch (BrandingImageNotStored $e) {
            return back()->withErrors(['file' => __($e->getMessage())]);
        }

        AuditLog::record('branding.image_uploaded', $user, null, ['image' => $image], $request->ip());

        $this->toast('success', 'Image uploaded.');

        return back();
    }

    public function destroy(Request $request, #[CurrentUser] User $user, string $image): RedirectResponse
    {
        try {
            Branding::remove($image);
        } catch (BrandingImageNotStored $e) {
            return back()->withErrors(['file' => __($e->getMessage())]);
        }

        AuditLog::record('branding.image_removed', $user, null, ['image' => $image], $request->ip());

        $this->toast('success', 'Image removed.');

        return back();
    }
}
