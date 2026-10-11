<?php

namespace App\Domain\Reporting;

/**
 * One report column. The type decides how a value is written: money in
 * major units with two decimals, timestamps in the business timezone.
 */
final class Column
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type = 'text', // text | int | money | percent | datetime | date
    ) {}

    /**
     * @return array{key: string, label: string, type: string}
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'label' => $this->label, 'type' => $this->type];
    }
}
