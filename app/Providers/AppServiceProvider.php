<?php

declare(strict_types=1);

namespace App\Providers;

use App\Filesystem\CloudinaryAdapter;
use App\Services\Tenancy\RestaurantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\FilesystemAdapter as LaravelFilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem as Flysystem;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One tenant context per request, or per queued job.
        $this->app->singleton(RestaurantContext::class);
    }

    public function boot(): void
    {
        $this->assertOtpIsNotExposedInProduction();
        $this->registerRateLimiters();
        $this->registerCloudinaryDisk();

        /*
         * Strict mode outside production: lazy loading, assigning attributes
         * that do not exist, and reading attributes that were never selected
         * all become exceptions. Catches N+1 queries where they are written
         * rather than in a Phase 8 performance audit.
         */
        Model::shouldBeStrict(! $this->app->isProduction());

        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }
    }

    /**
     * Coarse per-IP and per-user throttles.
     *
     * These sit in front of the precise per-phone quotas in OtpRateLimiter and
     * exist to shed load before it reaches the database, not to replace them.
     */
    private function registerRateLimiters(): void
    {
        RateLimiter::for('api', fn (Request $request) => $request->user()
            ? Limit::perMinute(60)->by('user:'.$request->user()->getAuthIdentifier())
            : Limit::perMinute(30)->by('ip:'.$request->ip()));

        RateLimiter::for('otp-send', fn (Request $request) => Limit::perMinute(10)->by('ip:'.$request->ip()));

        RateLimiter::for('otp-verify', fn (Request $request) => Limit::perMinute(20)->by('ip:'.$request->ip()));

        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(5)
            ->by(strtolower((string) $request->input('email')).'|'.$request->ip()));

        RateLimiter::for('orders', fn (Request $request) => Limit::perMinute(5)
            ->by('user:'.$request->user()?->getAuthIdentifier()));
    }

    /**
     * Registers the "cloudinary" disk driver.
     *
     * Render's disk is wiped on every deploy and every scale-to-zero, so
     * uploaded product/category images stored on the "public" disk there do
     * not survive. Setting FILESYSTEM_DISK=cloudinary routes those uploads to
     * Cloudinary instead, which is durable.
     *
     * cloudinary_php 1.x configures itself globally via \Cloudinary::config()
     * rather than an object passed around, so that happens here, once, from
     * the disk's own config array — never implicitly from getenv(), which
     * Laravel's env() does not keep in sync with.
     */
    private function registerCloudinaryDisk(): void
    {
        Storage::extend('cloudinary', function ($app, array $config) {
            if (! empty($config['connection_url'])) {
                \Cloudinary::config_from_url($config['connection_url']);
            }

            $adapter = new CloudinaryAdapter((string) \Cloudinary::config_get('cloud_name'));

            return new LaravelFilesystemAdapter(new Flysystem($adapter), $adapter, $config);
        });
    }

    /**
     * Second half of the OTP double gate.
     *
     * config/otp.php only honours expose_in_response in the local environment,
     * but a production deploy that sets the flag at all is a misconfiguration
     * worth failing loudly for, before it serves a single request.
     */
    private function assertOtpIsNotExposedInProduction(): void
    {
        if (! $this->app->isProduction()) {
            return;
        }

        if (config('otp.expose_in_response') === true) {
            throw new RuntimeException(
                'OTP_EXPOSE_IN_RESPONSE is enabled while APP_ENV=production. '
                .'Refusing to boot: this would return one-time codes in API responses.'
            );
        }

        if (config('otp.test_numbers') !== []) {
            throw new RuntimeException(
                'OTP_TEST_NUMBERS is set while APP_ENV=production. '
                .'Refusing to boot: fixed OTP codes must never exist in production.'
            );
        }
    }
}
