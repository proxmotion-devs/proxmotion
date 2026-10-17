<?php

declare(strict_types=1);

namespace App\Domain\Apps;

/**
 * One declared input of a recipe (docs/adr/0015). Values are validated
 * against the type and written to inputs.json, never into a script.
 */
final readonly class RecipeInput
{
    public const TYPES = ['string', 'password', 'integer', 'port', 'boolean', 'enum', 'slug', 'email', 'domain'];

    /**
     * @param  array{en: string, tr?: string}|null  $label
     * @param  list<string>  $options
     */
    public function __construct(
        public string $key,
        public string $type,
        public ?array $label,
        public bool $required,
        public string|int|bool|null $default,
        public array $options,
        public ?int $min,
        public ?int $max,
        public ?int $maxLength,
    ) {}

    /**
     * @param  array<string, mixed>  $data  a validated entry of recipe.yaml's inputs
     */
    public static function fromArray(array $data): self
    {
        $default = $data['default'] ?? null;
        $default = is_string($default) || is_int($default) || is_bool($default) ? $default : null;

        return new self(
            key: (string) $data['key'],
            type: (string) $data['type'],
            label: Recipe::text($data['label'] ?? null),
            // Without a default, an input must be given unless the recipe says otherwise.
            required: is_bool($data['required'] ?? null) ? $data['required'] : $default === null,
            default: $default,
            options: array_values(array_map('strval', is_array($data['options'] ?? null) ? $data['options'] : [])),
            min: is_int($data['min'] ?? null) ? $data['min'] : null,
            max: is_int($data['max'] ?? null) ? $data['max'] : null,
            maxLength: is_int($data['max_length'] ?? null) ? $data['max_length'] : null,
        );
    }

    public function isSecret(): bool
    {
        return $this->type === 'password';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'type' => $this->type,
            'label' => $this->label,
            'required' => $this->required,
            'default' => $this->default,
            'options' => $this->options === [] ? null : $this->options,
            'min' => $this->min ?? ($this->type === 'port' ? 1 : null),
            'max' => $this->max ?? ($this->type === 'port' ? 65535 : null),
            'max_length' => $this->maxLength,
        ];
    }
}
