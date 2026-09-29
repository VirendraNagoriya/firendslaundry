<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Supabase Auth
    |--------------------------------------------------------------------------
    |
    | Supabase is the identity provider. It stores and verifies the one-time
    | code sent to a phone number or email address, and hands back a signed
    | session. This application never handles the code itself; it only maps
    | the Supabase user id onto a row in our own `users` table, which is
    | where the role, business and branch live.
    |
    | To get these values: Supabase dashboard -> Project Settings -> API.
    | The service-role key is a secret: it bypasses row level security and
    | must only ever be read on the server. Never put it in a view, never
    | commit it, and rotate it if it is ever exposed.
    |
    */

    'url' => env('SUPABASE_URL'),
    'anon_key' => env('SUPABASE_ANON_KEY'),
    'service_role_key' => env('SUPABASE_SERVICE_ROLE_KEY'),

    /*
    | How a person identifies themselves at sign-in. Supabase sends the
    | one-time code over SMS for "phone" (which needs Twilio, MessageBird or
    | a custom SMS hook configured in the project) and over email for
    | "email" (which works out of the box on the built-in SMTP).
    */
    'identifier' => env('SUPABASE_IDENTIFIER', 'phone'),

    'country_code' => env('SUPABASE_COUNTRY_CODE', '91'),

    /*
    | How long a person has to type the code, in minutes. Kept short: a code
    | that is sitting in a phone's SMS inbox is a code an attacker with the
    | handset or a forwarded message can reuse.
    */
    'otp_expiry_minutes' => (int) env('SUPABASE_OTP_EXPIRY_MINUTES', 5),

    /*
    | Whether to actually call Supabase. Left off by default so a fresh
    | checkout boots to a clear "auth is not configured" screen instead of
    | a stack trace.
    */
    'enabled' => (bool) env('SUPABASE_AUTH_ENABLED', false),

    /*
    | Where to send a browser after a successful sign-in.
    */
    'redirect_to' => env('SUPABASE_REDIRECT_TO', '/dashboard'),

];
