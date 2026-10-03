<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        RateLimiter::for(
            'login',
            static fn (Request $request): Limit => Limit::perMinute(5)
                ->by(self::credentialThrottleKey('login', $request)),
        );
        RateLimiter::for(
            'device-token',
            static fn (Request $request): Limit => Limit::perMinute(5)
                ->by(self::credentialThrottleKey('device-token', $request)),
        );
    }

    private static function credentialThrottleKey(string $purpose, Request $request): string
    {
        $email = $request->input('email');
        $normalizedEmail = is_string($email) ? strtolower(trim($email)) : '';

        return hash('sha256', $purpose.'|'.$request->ip().'|'.$normalizedEmail);
    }
}
