<?php

namespace App\Modules\TaskOrchestration\Application\Registries;

use App\Models\Task;
use App\Modules\TaskOrchestration\Application\Contracts\ResultPublisher;

class ResultPublisherRegistry
{
    /**
     * @var array<int, ResultPublisher>|null
     */
    private ?array $ordered = null;

    /**
     * @param  iterable<ResultPublisher>  $publishers
     */
    public function __construct(
        private readonly iterable $publishers,
    ) {}

    /**
     * @return array<int, ResultPublisher>
     */
    public function all(): array
    {
        if ($this->ordered !== null) {
            return $this->ordered;
        }

        $publishers = is_array($this->publishers)
            ? $this->publishers
            : iterator_to_array($this->publishers, false);

        usort($publishers, static function (ResultPublisher $a, ResultPublisher $b): int {
            if ($a->priority() !== $b->priority()) {
                return $b->priority() <=> $a->priority();
            }

            return $a::class <=> $b::class;
        });

        return $this->ordered = $publishers;
    }

    /**
     * @return array<int, ResultPublisher>
     */
    public function matching(Task $task): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (ResultPublisher $publisher): bool => $publisher->supports($task),
        ));
    }

    public function publish(Task $task): void
    {
        foreach ($this->matching($task) as $publisher) {
            $publisher->publish($task);
        }
    }
}
