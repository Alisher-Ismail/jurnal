<?php

namespace App\Services\Telegram;

use RuntimeException;

/** A failed Bot API call. Carries the new id when a group was upgraded to a supergroup. */
class TelegramError extends RuntimeException
{
    public ?string $migrateTo = null;

    public static function from(string $method, ?array $json): self
    {
        $error = new self("Telegram {$method} failed: ".($json['description'] ?? 'no response'));
        if (isset($json['parameters']['migrate_to_chat_id'])) {
            $error->migrateTo = (string) $json['parameters']['migrate_to_chat_id'];
        }

        return $error;
    }

    /** Plain-language hint for the admin panel, or null when the message speaks for itself. */
    public static function hint(string $message): ?string
    {
        return match (true) {
            str_contains($message, 'chat not found') => __('The bot is not in this chat. Add the bot to the group again (then write any message there), or remove this id from "Editor chat ids" in Bot settings.'),
            str_contains($message, 'kicked') || str_contains($message, 'not a member') => __('The bot was removed from this chat. Add it again, or remove this id in Bot settings.'),
            str_contains($message, 'blocked by the user') || str_contains($message, "can't initiate") => __('This person has not started the bot (or blocked it). They should open the bot and press Start.'),
            str_contains($message, 'not enough rights') || str_contains($message, 'need administrator rights') || str_contains($message, 'member list is inaccessible') => __('Make the bot an admin of this channel with the right to post messages.'),
            default => null,
        };
    }
}
