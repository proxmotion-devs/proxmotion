<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domain\Settings\Branding;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A logo or favicon uploaded on the Branding page (docs/adr/0053): PNG, JPEG
 * or WebP by content, never SVG.
 */
class BrandingImageRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $file = ['required', 'file', 'mimetypes:'.implode(',', array_keys(Branding::TYPES))];

        return [
            'file' => $this->route('image') === 'favicon'
                // Browsers scale it down to 16 or 32 pixels; square, or it is squeezed.
                ? [...$file, 'max:256', 'dimensions:ratio=1,min_width=32,max_width=512']
                // Shown in a square of 32 to 36 pixels, scaled to fit.
                : [...$file, 'max:1024', 'dimensions:min_width=32,min_height=32,max_width=2048,max_height=2048'],
        ];
    }
}
