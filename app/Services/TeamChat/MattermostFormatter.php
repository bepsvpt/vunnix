<?php

namespace App\Services\TeamChat;

class MattermostFormatter implements ChatFormatterInterface
{
    private const URGENCY_COLORS = [
        'high' => '#dc2626',
        'medium' => '#f59e0b',
        'low' => '#22c55e',
        'info' => '#3b82f6',
    ];

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function format(string $type, string $message, array $context = []): array
    {
        $urgency = $context['urgency'] ?? 'info';
        $color = self::URGENCY_COLORS[$urgency] ?? self::URGENCY_COLORS['info'];

        $fields = [];
        if (! empty($context['project'])) {
            $fields[] = ['short' => true, 'title' => 'Project', 'value' => $context['project']];
        }
        if (! empty($context['urgency'])) {
            $fields[] = ['short' => true, 'title' => 'Urgency', 'value' => ucfirst($context['urgency'])];
        }

        $attachment = [
            'color' => $color,
            'text' => $message,
            'fields' => $fields,
        ];

        // Add action links as text footer
        if (! empty($context['links'])) {
            $linkTexts = array_map(fn ($l) => "[{$l['label']}]({$l['url']})", $context['links']);
            $attachment['text'] .= "\n\n".implode(' | ', $linkTexts);
        }

        return [
            'text' => $message,
            'attachments' => [$attachment],
        ];
    }
}
