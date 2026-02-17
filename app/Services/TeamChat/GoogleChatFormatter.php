<?php

namespace App\Services\TeamChat;

class GoogleChatFormatter implements ChatFormatterInterface
{
    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function format(string $type, string $message, array $context = []): array
    {
        $header = match ($type) {
            'alert' => 'Vunnix Alert',
            'task_failed' => 'Task Failed',
            default => 'Vunnix Notification',
        };

        $widgets = [
            [
                'textParagraph' => [
                    'text' => $message,
                ],
            ],
        ];

        // Add action links as buttons
        if (isset($context['links']) && $context['links'] !== []) {
            $buttons = [];
            foreach ($context['links'] as $link) {
                $buttons[] = [
                    'text' => $link['label'],
                    'onClick' => [
                        'openLink' => ['url' => $link['url']],
                    ],
                ];
            }
            $widgets[] = [
                'buttonList' => [
                    'buttons' => $buttons,
                ],
            ];
        }

        return [
            'text' => $message,
            'cardsV2' => [
                [
                    'cardId' => 'vunnix-notification',
                    'card' => [
                        'header' => [
                            'title' => $header,
                        ],
                        'sections' => [
                            ['widgets' => $widgets],
                        ],
                    ],
                ],
            ],
        ];
    }
}
