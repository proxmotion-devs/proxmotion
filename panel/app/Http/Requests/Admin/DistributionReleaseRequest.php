<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domain\Infrastructure\Distributions;
use App\Models\Distribution;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A release added to a distribution ("26.04", "rolling"). A distribution
 * built on another one with its own version numbers names the release of
 * the base it is matched as (Pardus 23 → Debian 12, docs/adr/0025).
 */
class DistributionReleaseRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $distribution = $this->distribution();
        $needsBase = $distribution->base !== null && ! $distribution->same_version;

        return [
            'version' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/', Rule::unique('distribution_releases', 'version')->where('distribution_id', $distribution->id)],
            'base_version' => $needsBase
                ? ['required', 'string', 'max:32', Rule::in(array_column(self::releasesOf((string) $distribution->base), 'version'))]
                : ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['version' => trim((string) $this->input('version'))]);

        if (trim((string) $this->input('base_version')) === '') {
            $this->request->remove('base_version');
        }
    }

    private function distribution(): Distribution
    {
        $distribution = $this->route('distribution');

        return $distribution instanceof Distribution ? $distribution : abort(404);
    }

    /**
     * @return list<array{id: int, version: string, base_version: string|null}>
     */
    private static function releasesOf(string $distribution): array
    {
        foreach (Distributions::forForm() as $entry) {
            if ($entry['id'] === $distribution) {
                return $entry['releases'];
            }
        }

        return [];
    }
}
