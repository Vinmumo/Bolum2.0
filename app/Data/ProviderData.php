<?php

namespace App\Data;

final readonly class ProviderData
{
    public function __construct(public string $name, public string $driver, public float $weight, public ?bool $isActive = null) {}

    /** Build only from input already accepted by a Form Request. */
    public static function fromValidated(array $data): self
    {
        return new self($data['name'], $data['driver'], (float) $data['weight'], isset($data['is_active']) ? (bool) $data['is_active'] : null);
    }

    public function attributes(): array
    {
        return array_filter(['name' => $this->name, 'driver' => $this->driver, 'weight' => $this->weight, 'is_active' => $this->isActive], fn ($value) => $value !== null);
    }
}
