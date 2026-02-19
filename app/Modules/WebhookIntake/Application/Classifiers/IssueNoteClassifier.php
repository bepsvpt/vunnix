<?php

namespace App\Modules\WebhookIntake\Application\Classifiers;

use App\Events\Webhook\NoteOnIssue;
use App\Events\Webhook\WebhookEvent;
use App\Modules\TaskOrchestration\Application\Contracts\IntentClassifier;
use App\Services\RoutingResult;

class IssueNoteClassifier implements IntentClassifier
{
    public function priority(): int
    {
        return 80;
    }

    public function supports(WebhookEvent $event): bool
    {
        return $event instanceof NoteOnIssue;
    }

    public function classify(WebhookEvent $event): ?RoutingResult
    {
        if (! $event instanceof NoteOnIssue) {
            return null;
        }

        if (! $this->containsAiMention($event->note)) {
            return null;
        }

        return new RoutingResult('issue_discussion', 'normal', $event);
    }

    private function containsAiMention(string $text): bool
    {
        return (bool) preg_match('/@ai\b/i', $text);
    }
}
