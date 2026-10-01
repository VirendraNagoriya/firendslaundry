<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Sign-in
    |--------------------------------------------------------------------------
    |
    | How somebody is allowed to prove who they are. Two independent methods
    | are supported and either is enough:
    |
    |   google    - sign in with a Google account. Free, and no password for
    |               the user to reuse or leak.
    |   password  - a first-party account created in this app.
    |
    | Neither method decides which laundry somebody belongs to, or what they
    | may do there. That comes from the `users` row an Admin created, never
    | from anything supplied here or at the login screen.
    |
    */

    'google' => [
        // Without a client id the "Continue with Google" button is hidden
        // rather than shown broken.
        'enabled' => (bool) env('GOOGLE_AUTH_ENABLED', true),

        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'password' => [
        'enabled' => (bool) env('PASSWORD_AUTH_ENABLED', true),

        // Long by design. A laundry account holds customer names, numbers
        // and addresses, and reused passwords are the normal case rather
        // than the exception.
        'min_length' => (int) env('PASSWORD_MIN_LENGTH', 12),

        'max_length' => 200,
    ],

    /*
    |--------------------------------------------------------------------------
    | Self-registration
    |--------------------------------------------------------------------------
    |
    | Customers may sign themselves up. Staff may not: a pickup boy, branch
    | user or owner is created by an Admin who assigns the role, so turning
    | this off removes the only route by which somebody could award
    | themselves a role.
    |
    */

    'registration' => [
        'open' => (bool) env('OPEN_CUSTOMER_REGISTRATION', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Throttling
    |--------------------------------------------------------------------------
    |
    | Brute-force protection for password sign-in. A Google sign-in cannot
    | be brute-forced this way because the credential check happens at
    | Google, not here.
    |
    */

    'throttle' => [
        'login_attempts' => (int) env('LOGIN_MAX_ATTEMPTS', 5),
        'login_decay_minutes' => (int) env('LOGIN_DECAY_MINUTES', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Business short code
    |--------------------------------------------------------------------------
    |
    | The code printed on a bill, used to say which laundry somebody is
    | signing in to. A phone number or an email address cannot do this job,
    | because the same person can be a customer of two different laundries.
    |
    */

    'business_code' => [
        'label' => env('BUSINESS_CODE_LABEL', 'Laundry code'),
        'placeholder' => env('BUSINESS_CODE_PLACEHOLDER', 'e.g. ABC'),
    ],

];
