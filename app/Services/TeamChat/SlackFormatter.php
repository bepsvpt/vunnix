<?php

namespace App\Services\TeamChat;

class SlackFormatter implements ChatFormatterInterface
{
    private const URGENCY_COLORS = [
        'high' => '#dc2626',    // red
        'medium' => '#f59e0b',  // amber
        'low' => '#22c55e',     // green
        'info' => '#3b82f6',    // blue
    ];

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function format(string $type, string $message, array $context = []): array
    {
        $urgency = $context['urgency'] ?? 'info';
        $color = self::URGENCY_COLORS[$urgency] ?? self::URGENCY_COLORS['info'];

        $blocks = [
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => $message,
                ],
            ],
        ];

        // Add action links as buttons if present
        if (! empty($context['links'])) {
            $elements = [];
            foreach ($context['links'] as $link) {
                $elements[] = [
                    'type' => 'button',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => $link['label'],
                    ],
                    'url' => $link['url'],
                ];
            }
            $blocks[] = [
                'type' => 'actions',
                'elements' => $elements,
            ];
        }

        return [
            'text' => $message,
            'attachments' => [
                [
                    'color' => $color,
                    'blocks' => $blocks,
                ],
            ],
        ];
    }
}
