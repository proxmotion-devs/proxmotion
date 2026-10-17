<?php

declare(strict_types=1);

namespace App\Domain\Apps;

/**
 * An action of an installed app, e.g. adding a VPN client (docs/adr/0016 §1,
 * §4): its script runs like the installer, with its own inputs and outputs.
 */
final readonly class RecipeAction
{
    /**
     * @param  array{en: string, tr?: string}  $label
     * @param  list<RecipeInput>  $inputs
     * @param  list<RecipeOutput>  $outputs
     */
    public function __construct(
        public string $key,
        public array $label,
        public string $script,
        public array $inputs,
        public array $outputs,
        public int $timeoutMinutes,
    ) {}

    /**
     * @param  array<string, mixed>  $data  a validated entry of recipe.yaml's actions
     */
    public static function fromArray(array $data): self
    {
        $entries = fn (mixed $value): array => array_values(array_filter(is_array($value) ? $value : [], 'is_array'));

        return new self(
            key: (string) $data['key'],
            // Format 1 actions have no label.
            label: Recipe::text($data['label'] ?? null) ?? ['en' => (string) $data['key']],
            script: (string) $data['script'],
            inputs: array_map(RecipeInput::fromArray(...), $entries($data['inputs'] ?? null)),
            outputs: array_map(RecipeOutput::fromArray(...), $entries($data['outputs'] ?? null)),
            timeoutMinutes: (int) ($data['timeout_minutes'] ?? 5),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'inputs' => array_map(fn (RecipeInput $input) => $input->toArray(), $this->inputs),
            'outputs' => array_map(fn (RecipeOutput $output) => $output->toArray(), $this->outputs),
        ];
    }
}
