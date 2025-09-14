<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Services\MicroserviceProxy;
use App\Services\ServiceHealthChecker;
use Illuminate\Support\Facades\Log;

class GatewayController extends Controller
{
    private $proxy;
    private $healthChecker;

    public function __construct(MicroserviceProxy $proxy, ServiceHealthChecker $healthChecker)
    {
        $this->proxy = $proxy;
        $this->healthChecker = $healthChecker;
    }

    public function forwardToService(Request $request): JsonResponse
    {
        $path = $request->path();
        $method = $request->method();

        // Determine which service to route to based on the path
        $serviceName = $this->determineService($path);
        $endpoint = $this->extractEndpoint($path);

        if (!$serviceName) {
            Log::warning("Invalid service path requested", [
                'path' => $path,
                'method' => $method,
                'available_services' => array_keys($this->getServiceMap())
            ]);

            return response()->json([
                'error' => 'Invalid service path',
                'message' => "The path '{$path}' does not match any configured service",
                'available_services' => array_keys($this->getServiceMap()),
                'supported_prefixes' => ['/api/courses', '/api/classes', '/api/trainees', '/api/results', '/api/exams'],
                'example_paths' => [
                    '/api/courses',
                    '/api/courses/1',
                    '/api/trainees/1/enroll',
                    '/api/exams/1/results'
                ]
            ], 400);
        }

        Log::info("Routing request", [
            'path' => $path,
            'method' => $method,
            'service' => $serviceName,
            'endpoint' => $endpoint,
            'segments' => explode('/', trim($path, '/')),
            'service_map' => $this->getServiceMap()
        ]);

        return $this->proxy->forwardRequest($request, $serviceName, $endpoint);
    }

    private function determineService(string $path): ?string
    {
        $segments = explode('/', trim($path, '/'));

        if (empty($segments)) {
            return null;
        }

        // If the first segment is 'api', skip it and look at the second segment
        $serviceIndex = ($segments[0] === 'api') ? 1 : 0;

        if (!isset($segments[$serviceIndex])) {
            return null;
        }

        $serviceSegment = $segments[$serviceIndex];

        // Map path segments to services
        $serviceMap = $this->getServiceMap();

        return $serviceMap[$serviceSegment] ?? null;
    }

    private function extractEndpoint(string $path): string
    {
        $segments = explode('/', trim($path, '/'));

        // If the first segment is 'api', skip it and the service identifier
        $startIndex = ($segments[0] === 'api') ? 2 : 1;

        // Get remaining segments after service identifier
        $remainingSegments = array_slice($segments, $startIndex);

        // Debug logging
        Log::info("Endpoint extraction debug", [
            'path' => $path,
            'segments' => $segments,
            'startIndex' => $startIndex,
            'remainingSegments' => $remainingSegments
        ]);

        // If no remaining segments, return the service name as the endpoint
        if (empty($remainingSegments)) {
            $endpoint = '/' . $segments[$startIndex - 1];
            Log::info("No remaining segments, using service name as endpoint", ['endpoint' => $endpoint]);
            return $endpoint;
        }

        // Include the service name in the endpoint for proper routing
        $endpoint = '/' . $segments[$startIndex - 1] . '/' . implode('/', $remainingSegments);
        Log::info("Using service name + remaining segments as endpoint", ['endpoint' => $endpoint]);
        return $endpoint;
    }

    private function getServiceMap(): array
    {
        return [
            'courses' => 'courses',
            'classes' => 'courses',
            'trainees' => 'trainees',
            'results' => 'trainees',
            'exams' => 'exams'
        ];
    }

    public function checkServicesHealth(): JsonResponse
    {
        $healthStatus = $this->healthChecker->checkAllServices();

        return response()->json([
            'timestamp' => now()->toISOString(),
            'services' => $healthStatus,
            'overall_status' => $this->getOverallStatus($healthStatus)
        ]);
    }

    public function getServiceHealthStatus(Request $request, string $serviceName): JsonResponse
    {
        try {
            $status = $this->proxy->getServiceHealthStatus($serviceName);

            return response()->json([
                'timestamp' => now()->toISOString(),
                'service_status' => $status
            ]);
        } catch (\Exception $e) {
            Log::error("Error getting service health status", [
                'service' => $serviceName,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Error getting service health status: ' . $e->getMessage(),
                'service' => $serviceName,
                'timestamp' => now()->toISOString()
            ], 500);
        }
    }

    public function getQueueStatus(): JsonResponse
    {
        try {
            $queueStatus = $this->proxy->getQueueStatus();

            return response()->json([
                'timestamp' => now()->toISOString(),
                'queue_status' => $queueStatus
            ]);
        } catch (\Exception $e) {
            Log::error("Error getting queue status", ['error' => $e->getMessage()]);

            return response()->json([
                'error' => 'Error getting queue status: ' . $e->getMessage(),
                'timestamp' => now()->toISOString()
            ], 500);
        }
    }

    public function processQueuedRequests(): JsonResponse
    {
        try {
            $result = $this->proxy->processQueuedRequests();

            if ($result) {
                return response()->json([
                    'message' => 'Queued requests processed successfully',
                    'timestamp' => now()->toISOString()
                ]);
            } else {
                return response()->json([
                    'error' => 'Failed to process queued requests',
                    'timestamp' => now()->toISOString()
                ], 500);
            }
        } catch (\Exception $e) {
            Log::error("Error processing queued requests", ['error' => $e->getMessage()]);

            return response()->json([
                'error' => 'Error processing queued requests: ' . $e->getMessage(),
                'timestamp' => now()->toISOString()
            ], 500);
        }
    }

    public function getQueuedRequests(Request $request): JsonResponse
    {
        try {
            $limit = $request->get('limit', 100);
            $result = $this->proxy->getQueuedRequests($limit);

            return response()->json([
                'timestamp' => now()->toISOString(),
                'requests' => $result['requests'] ?? [],
                'count' => $result['count'] ?? 0
            ]);
        } catch (\Exception $e) {
            Log::error("Error getting queued requests", ['error' => $e->getMessage()]);

            return response()->json([
                'error' => 'Error getting queued requests: ' . $e->getMessage(),
                'timestamp' => now()->toISOString()
            ], 500);
        }
    }

    public function retrySpecificRequest(Request $request): JsonResponse
    {
        try {
            $messageId = $request->get('message_id');
            $queueType = $request->get('queue_type', 'main');

            if (!$messageId) {
                return response()->json([
                    'error' => 'Message ID is required',
                    'timestamp' => now()->toISOString()
                ], 400);
            }

            $result = $this->proxy->retrySpecificRequest($messageId, $queueType);

            if ($result) {
                return response()->json([
                    'message' => 'Request retried successfully',
                    'message_id' => $messageId,
                    'queue_type' => $queueType,
                    'timestamp' => now()->toISOString()
                ]);
            } else {
                return response()->json([
                    'error' => 'Failed to retry request',
                    'message_id' => $messageId,
                    'queue_type' => $queueType,
                    'timestamp' => now()->toISOString()
                ], 500);
            }
        } catch (\Exception $e) {
            Log::error("Error retrying specific request", ['error' => $e->getMessage()]);

            return response()->json([
                'error' => 'Error retrying request: ' . $e->getMessage(),
                'timestamp' => now()->toISOString()
            ], 500);
        }
    }

    public function purgeQueue(Request $request): JsonResponse
    {
        try {
            $queueType = $request->get('queue_type', 'main');
            $result = $this->proxy->purgeQueue($queueType);

            if ($result) {
                return response()->json([
                    'message' => 'Queue purged successfully',
                    'queue_type' => $queueType,
                    'timestamp' => now()->toISOString()
                ]);
            } else {
                return response()->json([
                    'error' => 'Failed to purge queue',
                    'queue_type' => $queueType,
                    'timestamp' => now()->toISOString()
                ], 500);
            }
        } catch (\Exception $e) {
            Log::error("Error purging queue", ['error' => $e->getMessage()]);

            return response()->json([
                'error' => 'Error purging queue: ' . $e->getMessage(),
                'timestamp' => now()->toISOString()
            ], 500);
        }
    }

    public function getQueueHealth(): JsonResponse
    {
        try {
            $queueStatus = $this->proxy->getQueueStatus();
            $servicesHealth = $this->healthChecker->checkAllServices();

            $overallHealth = 'healthy';
            $issues = [];

            // Check queue lengths
            if (isset($queueStatus['request_queue']['message_count']) && $queueStatus['request_queue']['message_count'] > 1000) {
                $overallHealth = 'degraded';
                $issues[] = 'High number of queued requests: ' . $queueStatus['request_queue']['message_count'];
            }

            // Check service health
            $unhealthyServices = array_filter($servicesHealth, function($service) {
                return !$service['healthy'];
            });

            if (!empty($unhealthyServices)) {
                $overallHealth = 'degraded';
                $issues[] = 'Unhealthy services: ' . implode(', ', array_keys($unhealthyServices));
            }

            return response()->json([
                'timestamp' => now()->toISOString(),
                'overall_health' => $overallHealth,
                'issues' => $issues,
                'queue_status' => $queueStatus,
                'services_health' => $servicesHealth
            ]);
        } catch (\Exception $e) {
            Log::error("Error getting queue health", ['error' => $e->getMessage()]);

            return response()->json([
                'error' => 'Error getting queue health: ' . $e->getMessage(),
                'timestamp' => now()->toISOString()
            ], 500);
        }
    }

    public function getDeadLetterRequests(Request $request): JsonResponse
    {
        try {
            $limit = $request->get('limit', 100);
            $result = $this->proxy->getDeadLetterRequests($limit);

            return response()->json([
                'timestamp' => now()->toISOString(),
                'requests' => $result['requests'] ?? [],
                'count' => $result['count'] ?? 0
            ]);
        } catch (\Exception $e) {
            Log::error("Error getting dead letter requests", ['error' => $e->getMessage()]);

            return response()->json([
                'error' => 'Error getting dead letter requests: ' . $e->getMessage(),
                'timestamp' => now()->toISOString()
            ], 500);
        }
    }

    public function getQueueMetrics(): JsonResponse
    {
        try {
            $metrics = $this->proxy->getQueueMetrics();

            return response()->json([
                'timestamp' => now()->toISOString(),
                'metrics' => $metrics
            ]);
        } catch (\Exception $e) {
            Log::error("Error getting queue metrics", ['error' => $e->getMessage()]);

            return response()->json([
                'error' => 'Error getting queue metrics: ' . $e->getMessage(),
                'timestamp' => now()->toISOString()
            ], 500);
        }
    }

    public function resetQueueMetrics(): JsonResponse
    {
        try {
            $this->proxy->resetQueueMetrics();

            return response()->json([
                'message' => 'Queue metrics reset successfully',
                'timestamp' => now()->toISOString()
            ]);
        } catch (\Exception $e) {
            Log::error("Error resetting queue metrics", ['error' => $e->getMessage()]);

            return response()->json([
                'error' => 'Error resetting queue metrics: ' . $e->getMessage(),
                'timestamp' => now()->toISOString()
            ], 500);
        }
    }

    private function getOverallStatus(array $healthStatus): string
    {
        $healthyCount = 0;
        $totalCount = count($healthStatus);

        foreach ($healthStatus as $service) {
            if ($service['healthy']) {
                $healthyCount++;
            }
        }

        if ($healthyCount === $totalCount) {
            return 'healthy';
        } elseif ($healthyCount === 0) {
            return 'unhealthy';
        } else {
            return 'degraded';
        }
    }
}
