<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ServiceHealthChecker
{
    private $services;
    private $healthEndpoints;
    private $cacheTimeout = 30; // seconds

    public function __construct()
    {
        $this->services = [
            'courses' => env('COURSES_SERVICE_URL', 'http://courses_classes_service:8000'),
            'trainees' => env('TRAINEES_SERVICE_URL', 'http://trainees_service:8000'),
            'exams' => env('EXAMS_SERVICE_URL', 'http://exams_service:8000')
        ];

        $this->healthEndpoints = [
            'courses' => '/health',
            'trainees' => '/health',
            'exams' => '/health'
        ];
    }

    public function checkServiceHealth($serviceName): bool
    {
        $cacheKey = "service_health_{$serviceName}";

        // Check cache first
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $isHealthy = $this->performHealthCheck($serviceName);

        // Cache the result
        Cache::put($cacheKey, $isHealthy, $this->cacheTimeout);

        return $isHealthy;
    }

    public function checkAllServices(): array
    {
        $results = [];

        foreach (array_keys($this->services) as $serviceName) {
            $results[$serviceName] = [
                'healthy' => $this->checkServiceHealth($serviceName),
                'url' => $this->services[$serviceName],
                'last_check' => now()->toISOString()
            ];
        }

        return $results;
    }

    private function performHealthCheck($serviceName): bool
    {
        if (!isset($this->services[$serviceName]) || !isset($this->healthEndpoints[$serviceName])) {
            return false;
        }

        $url = $this->services[$serviceName] . '/api' . $this->healthEndpoints[$serviceName];

        try {
            $response = Http::timeout(5)->get($url);

            if ($response->successful()) {
                Log::info("Service {$serviceName} is healthy", ['url' => $url]);
                return true;
            }

            Log::warning("Service {$serviceName} health check failed", [
                'url' => $url,
                'status' => $response->status(),
                'response' => $response->body()
            ]);

            return false;

        } catch (\Exception $e) {
            Log::error("Service {$serviceName} health check error", [
                'url' => $url,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    public function getServiceUrl($serviceName): ?string
    {
        return $this->services[$serviceName] ?? null;
    }

    public function isServiceAvailable($serviceName): bool
    {
        return $this->checkServiceHealth($serviceName);
    }

    public function forceRefreshHealth($serviceName): bool
    {
        $cacheKey = "service_health_{$serviceName}";
        Cache::forget($cacheKey);

        return $this->checkServiceHealth($serviceName);
    }

    public function getHealthyServices(): array
    {
        $healthyServices = [];

        foreach (array_keys($this->services) as $serviceName) {
            if ($this->checkServiceHealth($serviceName)) {
                $healthyServices[] = $serviceName;
            }
        }

        return $healthyServices;
    }
}
