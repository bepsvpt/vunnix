<?php

namespace App\Modules\Observability\Application\Registries;

use App\Modules\Observability\Application\Contracts\AlertRule;

class AlertRuleRegistry
{
    /**
     * @var array<int, AlertRule>|null
     */
    private ?array $ordered = null;

    /**
     * @param  iterable<AlertRule>  $rules
     */
    public function __construct(
        private readonly iterable $rules,
    ) {}

    /**
     * @return array<int, AlertRule>
     */
    public function all(): array
    {
        if ($this->ordered !== null) {
            return $this->ordered;
        }

        $rules = is_array($this->rules)
            ? $this->rules
            : iterator_to_array($this->rules, false);

        usort($rules, static function (AlertRule $a, AlertRule $b): int {
            if ($a->priority() !== $b->priority()) {
                return $b->priority() <=> $a->priority();
            }

            return $a::class <=> $b::class;
        });

        return $this->ordered = $rules;
    }
}
