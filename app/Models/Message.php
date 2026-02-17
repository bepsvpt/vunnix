<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $conversation_id
 * @property int $user_id
 * @property string $agent
 * @property string $role
 * @property string $content
 * @property string $attachments
 * @property string $tool_calls
 * @property string $tool_results
 * @property string $usage
 * @property string $meta
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string|null $content_search
 * @property-read \App\Models\Conversation|null $conversation
 * @property-read \App\Models\User|null $user
 *
 * @method static \Database\Factories\MessageFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Message newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Message newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Message query()
 *
 * @mixin \Eloquent
 */
class Message extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $table = 'agent_conversation_messages';

    protected $keyType = 'string';

    protected $fillable = [
        'conversation_id',
        'user_id',
        'agent',
        'role',
        'content',
        'attachments',
        'tool_calls',
        'tool_results',
        'usage',
        'meta',
    ];

    protected static function booted(): void
    {
        static::creating(function (Message $message): void {
            if (! $message->id) {
                $message->id = (string) Str::uuid7();
            }
        });
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
