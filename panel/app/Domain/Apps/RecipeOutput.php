<?php

declare(strict_types=1);

namespace App\Domain\Apps;

/**
 * One declared output of a recipe (docs/adr/0015): a text or URL from
 * outputs.json, or a file from outputs/<filename>.
 */
final readonly class RecipeOutput
{
    /**
     * @param  array{en: string, tr?: string}|null  $label
     */
    public function __construct(
        public string $key,
        public string $type,
        public ?array $label,
        public ?string $filename,
        public bool $secret,
    ) {}

    /**
     * @param  array<string, mixed>  $data  a validated entry of recipe.yaml's outputs
     */
    public static function fromArray(array $data): self
    {
        return new self(
            key: (string) $data['key'],
            type: (string) $data['type'],
            label: Recipe::text($data['label'] ?? null),
            filename: is_string($data['filename'] ?? null) ? $data['filename'] : null,
            secret: (bool) ($data['secret'] ?? false),
        );
    }

    public function isFile(): bool
    {
        return $this->type === 'file';
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
            'filename' => $this->filename,
            'secret' => $this->secret,
        ];
    }
}
