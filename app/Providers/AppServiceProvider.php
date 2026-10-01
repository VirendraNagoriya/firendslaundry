<?php

namespace App\Providers;

use App\Services\OtpService;
use App\Services\Sms\LogDriver;
use App\Services\Sms\SmsDriver;
use App\Services\Sms\TextBeeDriver;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One tenant context per request, so the global scopes and the
        // controllers always agree on which business is in play.
        $this->app->singleton(TenantContext::class);

        $this->app->singleton(SmsDriver::class, function ($app) {
            $driver = config('sms.driver');

            return match ($driver) {
                'textbee' => new TextBeeDriver(
                    apiKey: config('sms.textbee.api_key'),
                    baseUrl: config('sms.textbee.base_url'),
                    timeout: (int) config('sms.textbee.timeout'),
                    fromName: (string) config('sms.from'),
                    defaultCountryCode: (string) config('sms.default_country_code'),
                ),

                'log' => new LogDriver,

                default => throw new InvalidArgumentException(
                    "Unknown SMS driver [{$driver}]. Use 'textbee' or 'log'."
                ),
            };
        });

        $this->app->singleton(OtpService::class, fn () => new OtpService(
            maxAttempts: (int) config('otp.max_attempts'),
        ));
    }

    public function boot(): void
    {
        // Catch the N+1 and accidental-save mistakes the spec warns about
        // during development. Turned off in production.
        Model::shouldBeStrict(! $this->app->isProduction());

        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
