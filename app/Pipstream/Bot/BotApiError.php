<?php

namespace App\Pipstream\Bot;

use RuntimeException;

class BotApiError extends RuntimeException
{
    /** The customer blocked the bot or deleted the chat: stop sending to it. */
    public function blocked(): bool
    {
        return $this->getCode() === 403 || str_contains($this->getMessage(), 'chat not found');
    }
}
