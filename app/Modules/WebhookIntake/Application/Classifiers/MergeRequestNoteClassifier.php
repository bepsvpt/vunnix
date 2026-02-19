<?php

namespace App\Modules\WebhookIntake\Application\Classifiers;

use App\Events\Webhook\NoteOnMR;
use App\Events\Webhook\WebhookEvent;
use App\Jobs\PostHelpResponse;
use App\Modules\TaskOrchestration\Application\Contracts\IntentClassifier;
use App\Services\RoutingResult;

class MergeRequestNoteClassifier implements IntentClassifier
{
    private const COMMANDS = [
        'review' => 'on_demand_review',
        'improve' => 'improve',
    ];

    public function priority(): int
    {
        return 90;
    }

    public function supports(WebhookEvent $event): bool
    {
        return $event instanceof NoteOnMR;
    }

    public function classify(WebhookEvent $event): ?RoutingResult
    {
        if (! $event instanceof NoteOnMR) {
            return null;
        }

        $note = $event->note;

        if (! $this->containsAiMention($note)) {
            return null;
        }

        if (preg_match('/@ai\s+ask\s+"([^"]+)"/', $note, $matches) === 1) {
            return new RoutingResult('ask_command', 'normal', $event, [
                'question' => $matches[1],
            ]);
        }

        foreach (self::COMMANDS as $command => $intent) {
            if (preg_match('/@ai\s+'.preg_quote($command, '/').'\b/i', $note) === 1) {
                $priority = $intent === 'on_demand_review' ? 'high' : 'normal';

                return new RoutingResult($intent, $priority, $event);
            }
        }

        $unrecognized = $this->extractUnrecognizedCommand($note);
        $this->dispatchHelpResponse($event, $unrecognized);

        return new RoutingResult('help_response', 'normal', $event);
    }

    private function containsAiMention(string $text): bool
    {
        return (bool) preg_match('/@ai\b/i', $text);
    }

    private function extractUnrecognizedCommand(string $note): string
    {
        if (preg_match('/@ai\s+(\S+)/', $note, $matches) === 1) {
            return '@ai '.$matches[1];
        }

        return '@ai';
    }

    private function dispatchHelpResponse(NoteOnMR $event, string $unrecognizedCommand): void
    {
        PostHelpResponse::dispatch(
            $event->gitlabProjectId,
            $event->mergeRequestIid,
            $unrecognizedCommand,
        );
    }
}
