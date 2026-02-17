<?php

namespace App\Services\TeamChat;

class GenericFormatter implements ChatFormatterInterface
{
    public function format(string $type, string $message, array $context = []): array
    {
        $text = $message;

        if (! empty($context['links'])) {
            $linkTexts = array_map(fn ($l) => "{$l['label']}: {$l['url']}", $context['links']);
            $text .= "\n\n".implode("\n", $linkTexts);
        }

        return ['text' => $text];
    }
}
