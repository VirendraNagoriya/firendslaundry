<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Code Length
    |--------------------------------------------------------------------------
    */

    'length' => (int) env('OTP_LENGTH', 6),

    /*
    |--------------------------------------------------------------------------
    | Expiry
    |--------------------------------------------------------------------------
    |
    | How long a code stays usable. The spec suggests 5 minutes.
    |
    */

    'ttl_minutes' => (int) env('OTP_TTL_MINUTES', 5),

    /*
    |--------------------------------------------------------------------------
    | Attempt Limits
    |--------------------------------------------------------------------------
    |
    | max_attempts    wrong codes allowed against one issued code before
    |                 it is burned and the user must request a new one.
    | max_resends     how many codes one mobile may request in an hour.
    | max_per_ip      how many codes one IP may request in an hour.
    |
    */

    'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 5),

    'max_resends' => (int) env('OTP_MAX_RESENDS_PER_HOUR', 3),

    'max_per_ip' => (int) env('OTP_MAX_PER_IP_PER_HOUR', 20),

    'resend_window_minutes' => (int) env('OTP_RESEND_WINDOW_MINUTES', 60),

    /*
    |--------------------------------------------------------------------------
    | Minimum Gap Between Sends
    |--------------------------------------------------------------------------
    |
    | Stops a client hammering the send endpoint in a tight loop.
    |
    */

    'min_seconds_between_sends' => (int) env('OTP_MIN_SECONDS_BETWEEN_SENDS', 30),

];
