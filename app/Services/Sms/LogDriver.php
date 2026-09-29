<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Log;

/**
 * Development / test transport: writes the message to the application log
 * instead of sending it. Lets the whole OTP flow be exercised on a
 * machine with no TextBee gateway phone attached.
 */
class LogDriver implements SmsDriver
{
    public function send(array $recipients, string $message): SmsResult
    {
        Log::channel('single')->info('SMS (log driver, not sent)', [
            'recipients' => $recipients,
            'message' => $message,
        ]);

        return SmsResult::accepted(providerId: 'log-driver');
    }
}
