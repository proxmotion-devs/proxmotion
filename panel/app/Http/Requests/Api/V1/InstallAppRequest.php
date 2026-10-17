<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\AppRecipe;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/servers/{id}/apps: the app and its inputs. The inputs are
 * checked against the recipe by InputValidator, eligibility by AppEligibility.
 */
class InstallAppRequest extends FormRequest
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
            'app' => ['required', 'string', Rule::exists('app_recipes', 'slug')],
            'inputs' => ['nullable', 'array'],
        ];
    }

    public function app(): AppRecipe
    {
        return AppRecipe::query()->where('slug', $this->string('app')->toString())->firstOrFail();
    }

    /**
     * @return array<array-key, mixed>
     */
    public function inputs(): array
    {
        $inputs = $this->input('inputs');

        return is_array($inputs) ? $inputs : [];
    }
}
