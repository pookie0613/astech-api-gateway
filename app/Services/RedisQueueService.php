<?php

namespace App\Services;

class RedisQueueService
{
    private $requestQueue = 'request_queue';
    private $responseQueue = 'response_queue';
    private $deadLetterQueue = 'dead_letter_queue';
    private $redis;
    private $connected = false;
    private $connectionError = null;
    private $primaryHost;
    private $primaryPort;
    private $fallbacks = [];
    private $lastErrors = [];

    public function __construct()
    {
        $this->primaryHost = env('REDIS_HOST', 'training_redis');
        $this->primaryPort = (int) env('REDIS_PORT', 6379);
        $this->fallbacks = [
            ['host' => '127.0.0.1', 'port' => 6379],
            ['host' => 'localhost', 'port' => 6379]
        ];

        $this->tryConnectAll();
    }

    private function attemptConnection(string $host, int $port): bool
    {
        // Try phpredis extension first
        try {
            if (class_exists(\Redis::class)) {
                $redisExt = new \Redis();
                if (@$redisExt->connect($host, $port, 1.5)) {
                    $pong = $redisExt->ping();
                    if ($pong === '+PONG' || $pong === 'PONG' || $pong === true) {
                        $this->redis = new class($redisExt) {
                            private $ext;
                            public function __construct($ext) { $this->ext = $ext; }
                            public function ping() { return $this->ext->ping(); }
                            public function llen($key) { return $this->ext->lLen($key); }
                            public function lrange($key, $start, $stop) { return $this->ext->lRange($key, $start, $stop); }
                            public function lpush($key, $value) { return $this->ext->lPush($key, $value); }
                            public function rpop($key) { return $this->ext->rPop($key); }
                            public function del($key) { return $this->ext->del($key); }
                            public function lrem($key, $count, $value) { return $this->ext->lRem($key, $value, $count); }
                        };
                        return true;
                    }
                    $this->lastErrors[] = "phpredis ping failed for {$host}:{$port}";
                } else {
                    $this->lastErrors[] = "phpredis connect failed for {$host}:{$port}";
                }
            }
        } catch (\Throwable $e) {
            $this->lastErrors[] = 'phpredis error: ' . $e->getMessage() . " [{$host}:{$port}]";
        }

        // Fallback to Predis client
        try {
            if (class_exists(\Predis\Client::class)) {
                $client = new \Predis\Client([
                    'host' => $host,
                    'port' => $port,
                    'scheme' => 'tcp',
                    'timeout' => 1.5,
                    'read_write_timeout' => 0
                ]);
                $result = $client->ping();
                if ($result === '+PONG' || $result === true || $result === 1) {
                    $this->redis = $client;
                    return true;
                }
                $this->lastErrors[] = "predis ping failed for {$host}:{$port}";
            } else {
                $this->lastErrors[] = 'Predis client class not found';
            }
        } catch (\Exception $e) {
            $this->lastErrors[] = 'predis error: ' . $e->getMessage() . " [{$host}:{$port}]";
        }

        return false;
    }

    private function tryConnectAll(): void
    {
        $this->connected = false;
        $this->lastErrors = [];
        if ($this->attemptConnection($this->primaryHost, $this->primaryPort)) {
            $this->connected = true;
            $this->connectionError = null;
            return;
        }
        foreach ($this->fallbacks as $cfg) {
            if ($this->attemptConnection($cfg['host'], $cfg['port'])) {
                $this->connected = true;
                $this->connectionError = null;
                return;
            }
        }
        $this->connectionError = implode(' | ', $this->lastErrors);
    }

    public function isConnected(): bool
    {
        if (!$this->redis) {
            // Try to (re)connect on demand
            $this->tryConnectAll();
            if (!$this->redis) return false;
        }
        try {
            $result = $this->redis->ping();
            return $result === '+PONG' || $result === true || $result === 1;
        } catch (\Exception $e) {
            // Attempt reconnect once if ping fails
            $this->tryConnectAll();
            return false;
        }
    }

    public function getQueueStatus()
    {
        try {
            if (!$this->isConnected()) {
                return [
                    'connected' => false,
                    'timestamp' => date('c'),
                    'request_queue' => [
                        'name' => $this->requestQueue,
                        'message_count' => 0,
                        'consumer_count' => 0
                    ],
                    'response_queue' => [
                        'name' => $this->responseQueue,
                        'message_count' => 0,
                        'consumer_count' => 0
                    ],
                    'dead_letter_queue' => [
                        'name' => $this->deadLetterQueue,
                        'message_count' => 0,
                        'consumer_count' => 0
                    ],
                    'total_queued' => 0,
                    'connection_error' => $this->connectionError
                ];
            }
            $requestQueueLength = $this->redis->llen($this->requestQueue);
            $responseQueueLength = $this->redis->llen($this->responseQueue);
            $deadLetterQueueLength = $this->redis->llen($this->deadLetterQueue);

            return [
                'connected' => $this->isConnected(),
                'timestamp' => date('c'),
                'request_queue' => [
                    'name' => $this->requestQueue,
                    'message_count' => $requestQueueLength,
                    'consumer_count' => 0
                ],
                'response_queue' => [
                    'name' => $this->responseQueue,
                    'message_count' => $responseQueueLength,
                    'consumer_count' => 0
                ],
                'dead_letter_queue' => [
                    'name' => $this->deadLetterQueue,
                    'message_count' => $deadLetterQueueLength,
                    'consumer_count' => 0
                ],
                'total_queued' => $requestQueueLength + $responseQueueLength + $deadLetterQueueLength,
                'connection_error' => $this->connectionError
            ];
        } catch (\Exception $e) {
            return [
                'connected' => false,
                'error' => $e->getMessage(),
                'timestamp' => date('c')
            ];
        }
    }

    public function queueRequest($requestData, $service, $endpoint)
    {
        try {
            if (!$this->isConnected()) {
                return false;
            }
            $message = [
                'id' => uniqid(),
                'timestamp' => date('c'),
                'service' => $service,
                'endpoint' => $endpoint,
                'method' => $requestData['method'] ?? 'GET',
                'data' => $requestData['data'] ?? [],
                'headers' => $requestData['headers'] ?? [],
                'retry_count' => 0,
                'max_retries' => 3,
                'priority' => $this->determinePriority($requestData['method'] ?? 'GET'),
                'user_id' => $requestData['user_id'] ?? null,
                'session_id' => $requestData['session_id'] ?? null
            ];

            $result = $this->redis->lpush($this->requestQueue, json_encode($message));

            if ($result) {
                return $message['id'];
            }

            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function determinePriority($method): int
    {
        $priorities = [
            'GET' => 1,
            'PUT' => 2,
            'POST' => 3,
            'DELETE' => 4,
        ];

        return $priorities[strtoupper($method)] ?? 1;
    }

    public function getQueuedRequests($limit = 100)
    {
        try {
            if (!$this->isConnected()) {
                return [
                    'requests' => [],
                    'count' => 0
                ];
            }
            $messages = $this->redis->lrange($this->requestQueue, 0, $limit - 1);

            $requests = [];
            foreach ($messages as $message) {
                $data = json_decode($message, true);
                if ($data) {
                    $requests[] = $data;
                }
            }

            return [
                'requests' => $requests,
                'count' => count($requests)
            ];
        } catch (\Exception $e) {
            return [
                'requests' => [],
                'count' => 0
            ];
        }
    }

    public function getDeadLetterRequests($limit = 100)
    {
        try {
            if (!$this->isConnected()) {
                return [
                    'requests' => [],
                    'count' => 0
                ];
            }
            $messages = $this->redis->lrange($this->deadLetterQueue, 0, $limit - 1);

            $requests = [];
            foreach ($messages as $message) {
                $data = json_decode($message, true);
                if ($data) {
                    $requests[] = $data;
                }
            }

            return [
                'requests' => $requests,
                'count' => count($requests)
            ];
        } catch (\Exception $e) {
            return [
                'requests' => [],
                'count' => 0
            ];
        }
    }

    public function getNextMessage($queueName = 'request_queue')
    {
        try {
            if (!$this->isConnected()) {
                return null;
            }
            $queue = $queueName === 'dead_letter_queue' ? $this->deadLetterQueue : $this->requestQueue;
            $message = $this->redis->rpop($queue);
            if (!$message) {
                return null;
            }
            $data = json_decode($message, true);
            return $data ?: null;
        } catch (\Exception $e) {
            return null;
        }
    }

    public function moveToDeadLetter(array $requestData): bool
    {
        try {
            if (!$this->isConnected()) {
                return false;
            }
            $requestData['dead_letter_timestamp'] = date('c');
            $result = $this->redis->lpush($this->deadLetterQueue, json_encode($requestData));
            return (bool) $result;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function purgeQueue($queueType = 'main')
    {
        try {
            if (!$this->isConnected()) {
                return false;
            }
            $queue = ($queueType === 'dead_letter') ? $this->deadLetterQueue : $this->requestQueue;
            $this->redis->del($queue);

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function retryMessage($messageId)
    {
        try {
            if (!$this->isConnected()) {
                return false;
            }
            $messages = $this->redis->lrange($this->requestQueue, 0, -1);

            foreach ($messages as $index => $message) {
                $requestData = json_decode($message, true);
                if ($requestData && $requestData['id'] === $messageId) {
                    // Remove from request queue
                    $this->redis->lrem($this->requestQueue, 1, $message);

                    // Reset retry count and add back to request queue
                    $requestData['retry_count'] = 0;
                    unset($requestData['dead_letter_timestamp']);

                    $this->redis->lpush($this->requestQueue, json_encode($requestData));

                    return true;
                }
            }

            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function findAndRemoveMessage(string $queueName, string $messageId)
    {
        try {
            if (!$this->isConnected()) {
                return null;
            }

            $queue = $queueName === 'dead_letter_queue' ? $this->deadLetterQueue : $this->requestQueue;
            $messages = $this->redis->lrange($queue, 0, -1);

            foreach ($messages as $message) {
                $data = json_decode($message, true);
                if ($data && isset($data['id']) && $data['id'] === $messageId) {
                    $this->redis->lrem($queue, 1, $message);
                    return $data;
                }
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }
}
