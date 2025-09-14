<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class QueueWorkerService
{
    private $redisQueueService;
    private $healthChecker;
    private $metrics = [
        'processed' => 0,
        'failed' => 0,
        'retried' => 0,
        'dead_lettered' => 0
    ];

    public function __construct(RedisQueueService $redisQueueService, ServiceHealthChecker $healthChecker)
    {
        $this->redisQueueService = $redisQueueService;
        $this->healthChecker = $healthChecker;
    }

    public function processQueuedRequests()
    {
        if (!$this->redisQueueService->isConnected()) {
            Log::warning('Redis not connected, cannot process queued requests');
            return false;
        }

        try {
            Log::info('Starting to process queued requests');

            $processedCount = 0;
            $maxMessages = 100; // Process max 100 messages per cycle

            for ($i = 0; $i < $maxMessages; $i++) {
                $msg = $this->redisQueueService->getNextMessage('request_queue');

                if (!$msg) {
                    Log::info('No more messages to process', ['processed_count' => $processedCount]);
                    break;
                }

                $this->processQueuedRequest($msg);
                $processedCount++;
            }

            Log::info('Finished processing queued requests', ['processed_count' => $processedCount]);
            return true;

        } catch (\Exception $e) {
            Log::error('Error processing queued requests: ' . $e->getMessage());
            return false;
        }
    }

    private function processQueuedRequest($msg)
    {
        try {
            $requestData = $msg; // Redis returns the data directly
            Log::info('Processing queued request', $requestData);

            // Check if service is now healthy
            if (!$this->healthChecker->isServiceAvailable($requestData['service'])) {
                $this->handleServiceUnavailable($requestData, $msg);
                return;
            }

            // Service is healthy, process the request
            $result = $this->executeQueuedRequest($requestData);

            if ($result) {
                $this->metrics['processed']++;
                Log::info('Queued request processed successfully', [
                    'service' => $requestData['service'],
                    'endpoint' => $requestData['endpoint'],
                    'message_id' => $requestData['id']
                ]);
            } else {
                $this->handleRequestFailure($requestData, $msg);
                return;
            }
        } catch (\Exception $e) {
            Log::error('Error processing queued request: ' . $e->getMessage(), [
                'request_data' => $requestData ?? 'unknown'
            ]);
            $this->handleRequestFailure($requestData ?? [], $msg);
        }
    }

    // Public method to process a single request payload immediately (used for targeted retry)
    public function processQueuedRequestData(array $requestData)
    {
        try {
            // Check if service is now healthy
            if (!$this->healthChecker->isServiceAvailable($requestData['service'])) {
                $this->handleServiceUnavailable($requestData, $requestData);
                return false;
            }

            $result = $this->executeQueuedRequest($requestData);
            if ($result) {
                $this->metrics['processed']++;
                return true;
            }
            $this->handleRequestFailure($requestData, $requestData);
            return false;
        } catch (\Exception $e) {
            Log::error('Error processing request data: ' . $e->getMessage());
            $this->handleRequestFailure($requestData, $requestData);
            return false;
        }
    }

    private function handleServiceUnavailable($requestData, $msg)
    {
        Log::info('Service still down, requeuing request', [
            'service' => $requestData['service'],
            'endpoint' => $requestData['endpoint'],
            'retry_count' => $requestData['retry_count']
        ]);

        // Increment retry count
        $requestData['retry_count']++;

        if ($requestData['retry_count'] >= $requestData['max_retries']) {
            $this->handleMaxRetriesExceeded($requestData, $msg);
            return;
        }

        // Calculate exponential backoff delay
        $delay = $this->calculateRetryDelay($requestData['retry_count']);

        // Requeue with delay
        $this->requeueRequest($requestData, $delay);
    }

    private function handleRequestFailure($requestData, $msg)
    {
        $this->metrics['failed']++;

        // Increment retry count
        $requestData['retry_count']++;

        if ($requestData['retry_count'] >= $requestData['max_retries']) {
            $this->handleMaxRetriesExceeded($requestData, $msg);
            return;
        }

        // Calculate exponential backoff delay
        $delay = $this->calculateRetryDelay($requestData['retry_count']);

        // Requeue with delay
        $this->requeueRequest($requestData, $delay);
    }

    private function handleMaxRetriesExceeded($requestData, $msg)
    {
        $this->metrics['dead_lettered']++;

        Log::warning('Max retries reached, moving to dead letter queue', [
            'service' => $requestData['service'],
            'endpoint' => $requestData['endpoint'],
            'retry_count' => $requestData['retry_count'],
            'message_id' => $requestData['id']
        ]);

        // Move to dead letter queue for monitoring
        $this->redisQueueService->moveToDeadLetter($requestData);
        $this->storeFailedRequest($requestData);

    }

    private function calculateRetryDelay($retryCount): int
    {
        // Exponential backoff: 2^retry_count seconds, max 60 seconds
        $delay = min(pow(2, $retryCount), 60);

        // Add jitter to prevent thundering herd
        $jitter = rand(0, 1000) / 1000; // 0-1 second random jitter
        $delay += $jitter;

        return (int) $delay;
    }

    private function storeFailedRequest($requestData)
    {
        try {
            // Store in cache for quick access
            $cacheKey = "failed_request_{$requestData['id']}";
            Cache::put($cacheKey, $requestData, 86400); // 24 hours

            // You could also store in database if needed
            // DB::table('failed_requests')->insert([
            //     'message_id' => $requestData['id'],
            //     'service' => $requestData['service'],
            //     'endpoint' => $requestData['endpoint'],
            //     'method' => $requestData['method'],
            //     'data' => json_encode($requestData['data']),
            //     'retry_count' => $requestData['retry_count'],
            //     'failed_at' => now(),
            //     'error_reason' => 'Max retries exceeded'
            // ]);

        } catch (\Exception $e) {
            Log::error('Failed to store failed request: ' . $e->getMessage());
        }
    }

    private function executeQueuedRequest($requestData)
    {
        $serviceUrl = $this->healthChecker->getServiceUrl($requestData['service']);

        if (!$serviceUrl) {
            Log::error('Service URL not found', ['service' => $requestData['service']]);
            return false;
        }

        $fullUrl = $serviceUrl . '/api' . $requestData['endpoint'];
        $method = strtolower($requestData['method']);
        $data = $requestData['data'] ?? [];
        $headers = $this->prepareHeaders($requestData['headers'] ?? []);

        try {
            $response = Http::withHeaders($headers)
                ->timeout(30)
                ->$method($fullUrl, $data);

            if ($response->successful()) {
                Log::info('Queued request executed successfully', [
                    'service' => $requestData['service'],
                    'endpoint' => $requestData['endpoint'],
                    'status' => $response->status(),
                    'message_id' => $requestData['id']
                ]);
                return true;
            } else {
                Log::warning('Queued request failed with status', [
                    'service' => $requestData['service'],
                    'endpoint' => $requestData['endpoint'],
                    'status' => $response->status(),
                    'response' => $response->body(),
                    'message_id' => $requestData['id']
                ]);
                return false;
            }

        } catch (\Exception $e) {
            Log::error('Error executing queued request', [
                'service' => $requestData['service'],
                'endpoint' => $requestData['endpoint'],
                'error' => $e->getMessage(),
                'message_id' => $requestData['id']
            ]);
            return false;
        }
    }

    private function requeueRequest($requestData, $delay = 0)
    {
        try {
            if ($delay > 0) {
                Log::info("Requeuing request with {$delay} second delay", [
                    'service' => $requestData['service'],
                    'endpoint' => $requestData['endpoint'],
                    'retry_count' => $requestData['retry_count']
                ]);

                sleep($delay);
            }

            $this->redisQueueService->queueRequest($requestData, $requestData['service'], $requestData['endpoint']);
            $this->metrics['retried']++;

            Log::info('Request requeued successfully', [
                'service' => $requestData['service'],
                'endpoint' => $requestData['endpoint'],
                'retry_count' => $requestData['retry_count'],
                'delay' => $delay
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to requeue request: ' . $e->getMessage(), $requestData);
        }
    }

    private function prepareHeaders($headers)
    {
        $preparedHeaders = [];

        // Filter and prepare headers
        $importantHeaders = ['Authorization', 'Content-Type', 'Accept', 'X-Requested-With'];

        foreach ($importantHeaders as $header) {
            if (isset($headers[$header])) {
                $preparedHeaders[$header] = $headers[$header];
            }
        }

        return $preparedHeaders;
    }

    public function getQueueStats()
    {
        $queueStatus = $this->redisQueueService->getQueueStatus();

        return array_merge($queueStatus, [
            'metrics' => $this->metrics,
            'timestamp' => now()->toISOString()
        ]);
    }

    public function resetMetrics()
    {
        $this->metrics = [
            'processed' => 0,
            'failed' => 0,
            'retried' => 0,
            'dead_lettered' => 0
        ];

        Log::info('Queue worker metrics reset');
    }

    public function getMetrics()
    {
        return $this->metrics;
    }

    public function processDeadLetterQueue()
    {
        if (!$this->redisQueueService->isConnected()) {
            Log::warning('Redis not connected, cannot process dead letter queue');
            return false;
        }

        try {
            Log::info('Starting to process dead letter queue');

            $processedCount = 0;
            $maxMessages = 50; // Process max 50 messages per cycle

            for ($i = 0; $i < $maxMessages; $i++) {
                $msg = $this->redisQueueService->getNextMessage('dead_letter_queue');

                if (!$msg) {
                    Log::info('No more dead letter messages to process', ['processed_count' => $processedCount]);
                    break;
                }

                $this->processDeadLetterMessage($msg);
                $processedCount++;
            }

            Log::info('Finished processing dead letter queue', ['processed_count' => $processedCount]);
            return true;

        } catch (\Exception $e) {
            Log::error('Error processing dead letter queue: ' . $e->getMessage());
            return false;
        }
    }

    private function processDeadLetterMessage($msg)
    {
        try {
            $requestData = $msg; // Redis returns the data directly
            Log::info('Processing dead letter message', $requestData);

            // Check if service is now healthy
            if (!$this->healthChecker->isServiceAvailable($requestData['service'])) {
                Log::info('Service still down, keeping in dead letter queue', [
                    'service' => $requestData['service'],
                    'endpoint' => $requestData['endpoint']
                ]);
                return;
            }

            // Service is healthy, try to process the request
            $result = $this->executeQueuedRequest($requestData);

            if ($result) {
                $this->metrics['processed']++;
                Log::info('Dead letter message processed successfully', [
                    'service' => $requestData['service'],
                    'endpoint' => $requestData['endpoint'],
                    'message_id' => $requestData['id']
                ]);

                // Acknowledge the message to remove it from dead letter queue
            } else {
                Log::warning('Dead letter message processing failed', [
                    'service' => $requestData['service'],
                    'endpoint' => $requestData['endpoint'],
                    'message_id' => $requestData['id']
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Error processing dead letter message: ' . $e->getMessage(), [
                'request_data' => $requestData ?? 'unknown'
            ]);
        }
    }
}
