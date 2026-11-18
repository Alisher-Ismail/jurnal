<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A bot user plus the step they are at in a multi-message conversation. */
class TelegramChat extends Model
{
    protected $fillable = ['chat_id', 'username', 'first_name', 'locale', 'state', 'data'];

    protected $casts = ['data' => 'array'];

    public function remember(array $values): void
    {
        $this->data = array_merge($this->data ?? [], $values);
    }

    public function reset(): void
    {
        $this->state = null;
        $this->data = null;
        $this->save();
    }
}
