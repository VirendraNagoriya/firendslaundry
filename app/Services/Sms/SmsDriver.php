<?php

namespace App\Services\Sms;

interface SmsDriver
{
    /**
     * Deliver a message to one or more recipients.
     *
     * @param  list<string>  $recipients  E.164 numbers.
     */
    public function send(array $recipients, string $message): SmsResult;
}
