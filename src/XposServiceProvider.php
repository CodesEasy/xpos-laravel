<?php

namespace CodesEasy\Xpos;

use CodesEasy\Xpos\Commands\XposCommand;
use Illuminate\Support\ServiceProvider;

class XposServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/xpos.php', 'xpos');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Publish config
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/xpos.php' => config_path('xpos.php'),
            ], 'xpos-config');

            $this->commands([
                XposCommand::class,
            ]);
        }

        // Auto-configure TrustProxies for HTTPS (only when accessed via XPOS)
        if (config('xpos.trust_proxies', true)) {
            $this->configureTrustProxies();
        }
    }

    /**
     * Configure TrustProxies to trust XPOS proxy.
     * Only applies when the request comes through *.xpos.to domain.
     */
    protected function configureTrustProxies(): void
    {
        if (!class_exists(\Illuminate\Http\Middleware\TrustProxies::class)) {
            return;
        }

        // Only configure if we have a request (not in console)
        if ($this->app->runningInConsole()) {
            return;
        }

        try {
            $host = request()->getHost();

            // Only trust proxy if accessed via xpos.to domain
            if (str_ends_with($host, '.xpos.to')) {
                \Illuminate\Http\Middleware\TrustProxies::at('*');
            }
        } catch (\Throwable) {
            // Silently fail if request not available
        }
    }
}
