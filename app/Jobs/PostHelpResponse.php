<?php

namespace App\Jobs;

use App\Services\GitLabClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Posts a help response on a GitLab MR when an unrecognized @ai command is used (D155).
 *
 * Dispatched asynchronously to avoid blocking the webhook response.
 */
class PostHelpResponse implements ShouldQueue
{
    use Queueable;

    private const HELP_MESSAGE = <<<'MD'
        **Available commands:**

        | Command | Description |
        |---------|-------------|
        | `@ai review` | Trigger a full code review |
        | `@ai improve` | Suggest improvements to this MR |
        | `@ai ask "your question"` | Ask a question about this code |

        Use any of the above commands in a comment on this merge request.
        MD;

    public function __construct(
        public readonly int $gitlabProjectId,
        public readonly int $mergeRequestIid,
        public readonly string $unrecognizedCommand,
    ) {
        $this->onQueue(\App\Support\QueueNames::SERVER);
    }

    public function handle(GitLabClient $gitLab): void
    {
        $body = "I didn't recognize the command `{$this->unrecognizedCommand}`.\n\n" . self::HELP_MESSAGE;

        try {
            $gitLab->createMergeRequestNote(
                $this->gitlabProjectId,
                $this->mergeRequestIid,
                $body,
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to post help response on MR', [
                'project_id' => $this->gitlabProjectId,
                'mr_iid' => $this->mergeRequestIid,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
