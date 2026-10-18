<?php

declare(strict_types=1);

namespace App\Domain\Apps;

use App\Rules\Hostname;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\ValidationException;

/**
 * Validates app inputs against the types their recipe declares (docs/adr/0015)
 * and fills in defaults. The result is written to inputs.json inside the
 * server; values never reach a shell command line.
 */
final class InputValidator
{
    private const NO_CONTROL_CHARACTERS = 'not_regex:/[\x00-\x1F\x7F]/';

    /**
     * @param  array<array-key, mixed>  $given
     * @param  string  $field  where the errors go: <field>.<key>
     * @return array<string, string|int|bool> the values to install with
     *
     * @throws ValidationException with errors under <field>.<key> (inputs.<key> by default)
     */
    public function validate(Recipe $recipe, array $given, string $field = 'inputs'): array
    {
        return $this->validateInputs($recipe->inputs, $given, $field);
    }

    /**
     * The same for any declared inputs, e.g. those of an action (docs/adr/0016 §4).
     *
     * @param  list<RecipeInput>  $declared
     * @param  array<array-key, mixed>  $given
     * @param  string  $field  where the errors go: <field>.<key>
     * @return array<string, string|int|bool>
     *
     * @throws ValidationException with errors under <field>.<key> (inputs.<key> by default)
     */
    public function validateInputs(array $declared, array $given, string $field = 'inputs'): array
    {
        $errors = [];
        $keys = array_map(fn (RecipeInput $input) => $input->key, $declared);

        foreach (array_keys($given) as $key) {
            if (! in_array((string) $key, $keys, true)) {
                $errors[$field.'.'.$key][] = __('This app has no input :key.', ['key' => (string) $key]);
            }
        }

        $values = [];

        foreach ($declared as $input) {
            $value = $given[$input->key] ?? $input->default;

            if ($value === null || $value === '') {
                if ($input->required) {
                    $errors[$field.'.'.$input->key][] = __('The :input field is required.', ['input' => $input->key]);
                }

                continue;
            }

            $validator = Validator::make([$input->key => $value], [$input->key => $this->rules($input)]);

            if ($validator->fails()) {
                $errors[$field.'.'.$input->key] = $validator->errors()->get($input->key);

                continue;
            }

            $values[$input->key] = match ($input->type) {
                'integer', 'port' => (int) $value,
                'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                default => (string) $value,
            };
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $values;
    }

    /**
     * Why an input's default does not fit its own type, or null (apps:validate).
     */
    public function problemWithDefault(RecipeInput $input): ?string
    {
        $validator = Validator::make([$input->key => $input->default], [$input->key => $this->rules($input)]);

        return $validator->fails() ? (string) $validator->errors()->first($input->key) : null;
    }

    /**
     * @return list<string|ValidationRule|In>
     */
    private function rules(RecipeInput $input): array
    {
        return match ($input->type) {
            'password' => ['string', 'min:'.($input->min ?? 12), 'max:'.($input->maxLength ?? 128), self::NO_CONTROL_CHARACTERS],
            'integer' => array_values(array_filter(['integer', $input->min === null ? null : 'min:'.$input->min, $input->max === null ? null : 'max:'.$input->max])),
            'port' => ['integer', 'between:'.max(1, $input->min ?? 1).','.min(65535, $input->max ?? 65535)],
            'boolean' => ['boolean'],
            'enum' => ['string', Rule::in($input->options)],
            'slug' => ['string', 'max:'.($input->maxLength ?? 64), 'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/'],
            'email' => ['string', 'max:254', 'email:rfc'],
            'domain' => ['string', 'max:253', new Hostname],
            default => ['string', 'max:'.($input->maxLength ?? 255), self::NO_CONTROL_CHARACTERS],
        };
    }
}
