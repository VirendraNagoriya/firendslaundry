<?php

return [

    /*
    |--------------------------------------------------------------------------
    | SMS Driver
    |--------------------------------------------------------------------------
    |
    | Which outbound SMS transport to use. "textbee" talks to the real
    | gateway; "log" writes the message to the application log instead,
    | which keeps the whole OTP flow testable on a machine with no
    | gateway phone attached.
    |
    */

    'driver' => env('SMS_DRIVER', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Sender Name
    |--------------------------------------------------------------------------
    |
    | Shown to the recipient. TextBee reads this from the gateway SIM.
    |
    */

    'from' => env('SMS_FROM_LAUNDRY', 'Laundry'),

    /*
    |--------------------------------------------------------------------------
    | TextBee
    |--------------------------------------------------------------------------
    |
    | The API key is read from the environment on every request and is
    | never written to config cache output in a repo, exposed to the
    | browser, or logged.
    |
    */

    'textbee' => [
        'api_key' => env('TEXTBEE_API_KEY'),
        'base_url' => env('TEXTBEE_BASE_URL', 'https://api.textbee.dev/api/v1/gateway/send-sms'),
        'timeout' => (int) env('TEXTBEE_TIMEOUT', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Country Code
    |--------------------------------------------------------------------------
    |
    | Used when a user types a local 10-digit number instead of a full
    | international one. Digits only, no "+" or "00".
    |
    */

    'default_country_code' => env('SMS_DEFAULT_COUNTRY_CODE', '91'),

];
