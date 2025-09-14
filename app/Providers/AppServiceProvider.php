<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(\App\Services\ServiceHealthChecker::class, function ($app) {
            return new \App\Services\ServiceHealthChecker();
        });

        // Bind RedisQueueService
        $this->app->singleton(\App\Services\RedisQueueService::class, function ($app) {
            return new \App\Services\RedisQueueService();
        });

        // Bind QueueWorkerService with required dependencies
        $this->app->singleton(\App\Services\QueueWorkerService::class, function ($app) {
            return new \App\Services\QueueWorkerService(
                $app->make(\App\Services\RedisQueueService::class),
                $app->make(\App\Services\ServiceHealthChecker::class)
            );
        });

        // Bind MicroserviceProxy with all dependencies
        $this->app->singleton(\App\Services\MicroserviceProxy::class, function ($app) {
            return new \App\Services\MicroserviceProxy(
                $app->make(\App\Services\ServiceHealthChecker::class),
                $app->make(\App\Services\RedisQueueService::class),
                $app->make(\App\Services\QueueWorkerService::class)
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
