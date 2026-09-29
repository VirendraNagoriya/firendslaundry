<?php

namespace App\Services;

use App\Services\Sms\SmsDriver;
use App\Services\Sms\SmsResult;

/**
 * Facade over the configured SMS transport.
 *
 * Keeping the transport behind this one class means the OTP flow never
 * knows which gateway it is talking to, and a backup gateway can be
 * introduced later by adding a driver and a config switch.
 */
class TextBeeService
{
    public function __construct(private readonly SmsDriver $driver)
    {
    }

    /**
     * @param  list<string>  $recipients  E.164 numbers.
     */
    public function send(array $recipients, string $message): SmsResult
    {
        return $this->driver->send($recipients, $message);
    }

    public function sendTo(string $recipient, string $message): SmsResult
    {
        return $this->driver->send([$recipient], $message);
    }

    public function driverName(): string
    {
        return config('sms.driver');
    }
}
