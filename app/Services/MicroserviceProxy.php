<?php

namespace App\Services;

use App\Services\ServiceHealthChecker;
use App\Services\RedisQueueService;
use App\Services\QueueWorkerService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class MicroserviceProxy
{
    private $healthChecker;
    private $redisQueueService;
    private $queueWorker;

    public function __construct(ServiceHealthChecker $healthChecker, RedisQueueService $redisQueueService, QueueWorkerService $queueWorker)
    {
        $this->healthChecker = $healthChecker;
        $this->redisQueueService = $redisQueueService;
        $this->queueWorker = $queueWorker;
    }

    public function forwardRequest(Request $request, string $serviceName, string $endpoint)
    {
        // Check if service is healthy
        if ($this->healthChecker->isServiceAvailable($serviceName)) {
            return $this->makeDirectRequest($request, $serviceName, $endpoint);
        }

        // Service is down, only queue mutating methods
        $method = strtoupper($request->method());
        if (in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
            return $this->queueRequest($request, $serviceName, $endpoint);
        }

        // For non-mutating methods, do not queue
        return response()->json([
            'error' => 'Service temporarily unavailable',
            'queued' => false,
            'service' => $serviceName,
            'endpoint' => $endpoint,
            'method' => $method,
            'timestamp' => now()->toISOString()
        ], 503);
    }

    private function makeDirectRequest(Request $request, string $serviceName, string $endpoint)
    {
        $serviceUrl = $this->healthChecker->getServiceUrl($serviceName);

        if (!$serviceUrl) {
            return response()->json(['error' => 'Service not configured'], 500);
        }

        $fullUrl = $serviceUrl . '/api' . $endpoint;

        try {
            $method = strtolower($request->method());
            $headers = $this->prepareHeaders($request);
            $data = $this->prepareData($request);

            $response = Http::withHeaders($headers)
                ->timeout(30)
                ->$method($fullUrl, $data);

            Log::info("Request forwarded successfully", [
                'service' => $serviceName,
                'endpoint' => $endpoint,
                'method' => $method,
                'status' => $response->status(),
                'user_id' => $this->getUserId($request),
                'ip' => $request->ip()
            ]);

            return response()->json($response->json(), $response->status());

        } catch (\Exception $e) {
            Log::error("Error forwarding request to service", [
                'service' => $serviceName,
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
                'user_id' => $this->getUserId($request),
                'ip' => $request->ip()
            ]);

            // If direct request fails, only queue mutating methods
            $method = strtoupper($request->method());
            if (in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
                return $this->queueRequest($request, $serviceName, $endpoint);
            }

            return response()->json([
                'error' => 'Service error and request was not queued (non-mutating method)',
                'queued' => false,
                'service' => $serviceName,
                'endpoint' => $endpoint,
                'method' => $method,
                'timestamp' => now()->toISOString()
            ], 503);
        }
    }

    private function queueRequest(Request $request, string $serviceName, string $endpoint)
    {
        // Check if Redis is available
        if (!$this->redisQueueService->isConnected()) {
            Log::warning("Redis not available, cannot queue request", [
                'service' => $serviceName,
                'endpoint' => $endpoint,
                'user_id' => $this->getUserId($request)
            ]);

            // Store request in cache as a fallback when Redis is down
            $this->storeRequestInCache($request, $serviceName, $endpoint);

            return response()->json([
                'error' => 'Service temporarily unavailable and request queuing is not available',
                'message' => 'Request has been cached and will be retried when services are available',
                'service' => $serviceName,
                'endpoint' => $endpoint,
                'timestamp' => now()->toISOString(),
                'queued' => false,
                'cached' => true,
                'retry_later' => true
            ], 503);
        }

        $requestData = [
            'method' => $request->method(),
            'data' => $request->all(),
            'headers' => $request->headers->all(),
            'endpoint' => $endpoint,
            'user_id' => $this->getUserId($request),
            'session_id' => $this->generateSessionId($request), // Generate unique ID instead of using session
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'request_id' => uniqid('req_', true),
            'queued_at' => now()->toISOString()
        ];

        $messageId = $this->redisQueueService->queueRequest($requestData, $serviceName, $endpoint);

        if ($messageId) {
            Log::info("Request queued successfully", [
                'service' => $serviceName,
                'endpoint' => $endpoint,
                'message_id' => $messageId,
                'user_id' => $this->getUserId($request),
                'method' => $request->method(),
                'request_id' => $requestData['request_id']
            ]);

            return response()->json([
                'message' => 'Service temporarily unavailable. Request has been queued.',
                'queued' => true,
                'message_id' => $messageId,
                'request_id' => $requestData['request_id'],
                'service' => $serviceName,
                'endpoint' => $endpoint,
                'timestamp' => now()->toISOString(),
                'estimated_retry_time' => $this->estimateRetryTime($serviceName),
                'status' => 'queued'
            ], 503);
        }

        Log::error("Failed to queue request", [
            'service' => $serviceName,
            'endpoint' => $endpoint,
            'user_id' => $this->getUserId($request)
        ]);

        // Fallback to cache storage
        $this->storeRequestInCache($request, $serviceName, $endpoint);

        return response()->json([
            'error' => 'Service unavailable and failed to queue request',
            'message' => 'Request has been cached as fallback',
            'service' => $serviceName,
            'endpoint' => $endpoint,
            'timestamp' => now()->toISOString(),
            'queued' => false,
            'cached' => true
        ], 503);
    }

    private function storeRequestInCache(Request $request, string $serviceName, string $endpoint)
    {
        try {
            $cacheKey = "cached_request_{$serviceName}_{$endpoint}_" . uniqid();
            $requestData = [
                'method' => $request->method(),
                'data' => $request->all(),
                'headers' => $request->headers->all(),
                'endpoint' => $endpoint,
                'service' => $serviceName,
                'user_id' => $this->getUserId($request),
                'timestamp' => now()->toISOString(),
                'request_id' => uniqid('cached_req_', true)
            ];

            Cache::put($cacheKey, $requestData, 3600); // Cache for 1 hour

            Log::info("Request cached as fallback", [
                'cache_key' => $cacheKey,
                'service' => $serviceName,
                'endpoint' => $endpoint
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to cache request: " . $e->getMessage());
        }
    }

    private function generateSessionId(Request $request): string
    {
        // Generate a unique session ID based on request data
        $uniqueData = $request->ip() . $request->userAgent() . $request->header('X-Requested-With', '');
        return hash('sha256', $uniqueData . time());
    }

    private function getUserId(Request $request): ?string
    {
        try {
            if (Auth::check()) {
                return Auth::id();
            }

            // Check for API token in headers
            $token = $request->header('Authorization');
            if ($token && str_starts_with($token, 'Bearer ')) {
                // You could decode the token here to get user ID
                // For now, return a hash of the token
                return hash('sha256', $token);
            }

            return null;
        } catch (\Exception $e) {
            Log::warning('Error getting user ID: ' . $e->getMessage());
            return null;
        }
    }

    private function estimateRetryTime(string $serviceName): string
    {
        // Estimate retry time based on service health history
        $cacheKey = "service_retry_estimate_{$serviceName}";

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        // Default estimate: 30 seconds
        $estimate = '30 seconds';
        Cache::put($cacheKey, $estimate, 300); // Cache for 5 minutes

        return $estimate;
    }

    private function prepareHeaders(Request $request): array
    {
        $headers = $request->headers->all();

        // Remove problematic headers that might cause issues
        unset($headers['host']);
        unset($headers['content-length']);

        // Ensure content-type is set
        if (!isset($headers['content-type'])) {
            $headers['content-type'] = ['application/json'];
        }

        return $headers;
    }

    private function prepareData(Request $request): array
    {
        if ($request->isMethod('GET')) {
            return $request->query();
        }

        return $request->all();
    }

    public function processQueuedRequests()
    {
        return $this->queueWorker->processQueuedRequests();
    }

    public function processDeadLetterQueue()
    {
        return $this->queueWorker->processDeadLetterQueue();
    }

    public function getQueueStatus()
    {
        return $this->redisQueueService->getQueueStatus();
    }

    public function getQueueMetrics()
    {
        return $this->queueWorker->getMetrics();
    }

    public function resetQueueMetrics()
    {
        return $this->queueWorker->resetMetrics();
    }

    public function getServiceHealthStatus(string $serviceName)
    {
        return [
            'service' => $serviceName,
            'healthy' => $this->healthChecker->isServiceAvailable($serviceName),
            'url' => $this->healthChecker->getServiceUrl($serviceName),
            'last_check' => Cache::get("service_health_{$serviceName}_last_check"),
            'queue_status' => $this->getQueueStatus()
        ];
    }

    public function getDetailedQueueStatus()
    {
        return $this->redisQueueService->getQueueStatus();
    }

    public function getQueuedRequests($limit = 100)
    {
        return $this->redisQueueService->getQueuedRequests($limit);
    }

    public function getDeadLetterRequests($limit = 100)
    {
        return $this->redisQueueService->getDeadLetterRequests($limit);
    }

    public function retrySpecificRequest($messageId, $queueType = 'main')
    {
        $queueName = $queueType === 'dead_letter' ? 'dead_letter_queue' : 'request_queue';
        $requestData = $this->redisQueueService->findAndRemoveMessage($queueName, $messageId);

        if (!$requestData) {
            return false;
        }

        return $this->queueWorker->processQueuedRequestData($requestData);
    }

    public function purgeQueue($queueType = 'main')
    {
        return $this->redisQueueService->purgeQueue($queueType);
    }
}
