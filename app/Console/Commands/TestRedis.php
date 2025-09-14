<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\RedisQueueService;
use Illuminate\Support\Facades\Log;

class TestRedis extends Command
{
    protected $signature = 'redis:test {--verbose : Show detailed output}';
    protected $description = 'Test Redis connection and queue functionality';

    public function handle()
    {
        $this->info('Testing Redis connection...');

        // First, test basic network connectivity
        $this->info('\n=== Network Connectivity Test ===');
        $this->testBasicConnectivity();

        // Then test the full service
        $this->info('\n=== Full Service Test ===');
        $this->testFullService();

        return 0;
    }

    private function testBasicConnectivity()
    {
        $host = config('redis.host', 'localhost');
        $port = config('redis.port', 6379);

        $this->info("Testing connection to {$host}:{$port}...");

        // Test 1: Basic socket connection
        $connection = @fsockopen($host, $port, $errno, $errstr, 5);

        if ($connection) {
            $this->info("✓ Basic socket connection successful");
            fclose($connection);
        } else {
            $this->error("✗ Basic socket connection failed: {$errstr} ({$errno})");
            return;
        }

        // Test 2: Try to create a simple Redis connection
        $this->info("Testing basic Redis connection...");

        try {
            $testKey = 'redis_connection_test_' . uniqid();
            \Redis::set($testKey, 'test', 'EX', 10);
            $result = \Redis::get($testKey);
            \Redis::del($testKey);

            if ($result === 'test') {
                $this->info("✓ Basic Redis connection successful");
            } else {
                throw new \Exception('Redis connection test failed');
            }

        } catch (\Exception $e) {
            $this->error("✗ Basic Redis connection failed: " . $e->getMessage());
            $this->error("Error details: " . $e->getFile() . ":" . $e->getLine());
        }
    }

    private function testFullService()
    {
        try {
            $redisQueue = new RedisQueueService();

            if ($redisQueue->isConnected()) {
                $this->info('✓ Redis service connected successfully');

                if ($this->option('verbose')) {
                    $this->info('Testing queue operations...');

                    // Test queue status
                    $status = $redisQueue->getQueueStatus();
                    $this->info('✓ Queue status retrieved successfully');

                    if ($status['connected']) {
                        $this->table(
                            ['Queue', 'Messages', 'Consumers'],
                            [
                                ['Request Queue', $status['request_queue']['message_count'], $status['request_queue']['consumer_count']],
                                ['Response Queue', $status['response_queue']['message_count'], $status['response_queue']['consumer_count']],
                                ['Dead Letter Queue', $status['dead_letter_queue']['message_count'], $status['dead_letter_queue']['consumer_count']]
                            ]
                        );

                        $this->info('Total queued messages: ' . $status['total_queued']);
                    }
                }

                // Test publishing a message
                $this->info('Testing message publishing...');
                $testMessage = [
                    'test' => true,
                    'timestamp' => now()->toISOString(),
                    'message' => 'Test message from command'
                ];

                $result = $redisQueue->publish($testMessage, 'test.routing.key');

                if ($result) {
                    $this->info('✓ Test message published successfully');
                } else {
                    $this->warn('⚠ Failed to publish test message');
                }

                // Test queueing a request
                $this->info('Testing request queueing...');
                $testRequest = [
                    'method' => 'POST',
                    'data' => ['test' => 'data'],
                    'headers' => ['Content-Type' => 'application/json']
                ];

                $messageId = $redisQueue->queueRequest($testRequest, 'test_service', '/test/endpoint');

                if ($messageId) {
                    $this->info('✓ Test request queued successfully with ID: ' . $messageId);
                } else {
                    $this->warn('⚠ Failed to queue test request');
                }

            } else {
                $this->error('✗ Redis service connection failed');

                // Check configuration
                $this->info('Checking configuration...');
                $this->info('Host: ' . config('redis.host'));
                $this->info('Port: ' . config('redis.port'));
                $this->info('Database: ' . config('redis.database'));

                // Check if Redis service is running
                $this->info('Checking if Redis service is accessible...');
                $host = config('redis.host');
                $port = config('redis.port');

                $connection = @fsockopen($host, $port, $errno, $errstr, 5);

                if ($connection) {
                    $this->info('✓ Redis service is accessible on ' . $host . ':' . $port);
                    fclose($connection);
                } else {
                    $this->error('✗ Cannot connect to Redis service on ' . $host . ':' . $port);
                    $this->error('Error: ' . $errstr . ' (' . $errno . ')');

                    $this->info('Troubleshooting tips:');
                    $this->info('1. Make sure Redis container is running: docker ps | grep redis');
                    $this->info('2. Check if port 6379 is accessible: telnet ' . $host . ' 6379');
                    $this->info('3. Verify credentials in config/redis.php');
                    $this->info('4. Check Redis logs: docker logs training_redis');
                }
            }

        } catch (\Exception $e) {
            $this->error('✗ Test failed with exception: ' . $e->getMessage());

            if ($this->option('verbose')) {
                $this->error('Stack trace:');
                $this->error($e->getTraceAsString());
            }

            Log::error('Redis test failed: ' . $e->getMessage());
        }
    }
}
