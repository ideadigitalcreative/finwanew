<?php

namespace App\Records;

use Laravel\Pulse\Records\Gauge;

class TelegramMessages extends Gauge
{
    /**
     * The type of the record.
     */
    public static string $type = 'telegram_messages';

    /**
     * Configure the Pulse record.
     */
    public function __construct(
        public string $key = 'messages_sent',
        public int $value = 0,
        public ?int $total = null,
    ) {
        //
    }
}
