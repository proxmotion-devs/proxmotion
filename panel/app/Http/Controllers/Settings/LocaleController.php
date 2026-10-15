<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Locales;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LocaleController extends Controller
{
    /**
     * Switch the UI language for the current session (and the user, if signed in).
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'string', Rule::in(Locales::SUPPORTED)],
        ]);

        $request->session()->put('locale', $validated['locale']);

        $user = $request->user();

        if ($user instanceof User) {
            $user->update(['locale' => $validated['locale']]);
        }

        return back();
    }
}
