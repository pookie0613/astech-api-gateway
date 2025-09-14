<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\MicroserviceProxy;
use App\Services\ServiceHealthChecker;
use Illuminate\Support\Facades\Log;

class MonitorQueueHealth extends Command
{
    protected $signature = 'queue:monitor {--continuous : Monitor continuously} {--interval=5 : Interval between checks in seconds}';
    protected $description = 'Monitor Redis queue health and service availability';

    private $proxy;
    private $healthChecker;

    public function __construct(MicroserviceProxy $proxy, ServiceHealthChecker $healthChecker)
    {
        parent::__construct();
        $this->proxy = $proxy;
        $this->healthChecker = $healthChecker;
    }

    public function handle()
    {
        $continuous = $this->option('continuous');
        $interval = (int) $this->option('interval');

        $this->info('🚀 Starting Queue Health Monitor');
        $this->info('Mode: ' . ($continuous ? 'Continuous' : 'Single run'));
        $this->info('Interval: ' . $interval . ' seconds');

        if ($continuous) {
            $this->info('Press Ctrl+C to stop monitoring');
            $this->runContinuous($interval);
        } else {
            $this->runOnce();
        }

        return 0;
    }

    private function runOnce()
    {
        try {
            $this->displayQueueStatus();
            $this->displayServiceHealth();
        } catch (\Exception $e) {
            $this->error('Error monitoring queue health: ' . $e->getMessage());
            Log::error('Error monitoring queue health', ['error' => $e->getMessage()]);
        }
    }

    private function runContinuous($interval)
    {
        while (true) {
            try {
                $this->displayQueueStatus();
                $this->displayServiceHealth();

                $this->info("⏳ Waiting {$interval} seconds before next check...");
                sleep($interval);

            } catch (\Exception $e) {
                $this->error('Error in continuous monitoring: ' . $e->getMessage());
                Log::error('Error in continuous monitoring', ['error' => $e->getMessage()]);
                sleep($interval);
            }
        }
    }

    private function displayQueueStatus()
    {
        $this->newLine();
        $this->info('📊 Queue Status:');
        $this->info('================');

        try {
            $queueStatus = $this->proxy->getQueueStatus();

            if (isset($queueStatus['request_queue'])) {
                $requestCount = $queueStatus['request_queue']['message_count'] ?? 0;
                $status = $requestCount > 0 ? '⚠️' : '✅';
                $this->info("{$status} Request Queue: {$requestCount} messages");
            }

            if (isset($queueStatus['dead_letter_queue'])) {
                $deadLetterCount = $queueStatus['dead_letter_queue']['message_count'] ?? 0;
                $status = $deadLetterCount > 0 ? '❌' : '✅';
                $this->info("{$status} Dead Letter Queue: {$deadLetterCount} messages");
            }

            if (isset($queueStatus['total_queued'])) {
                $this->info("📈 Total Queued: {$queueStatus['total_queued']} messages");
            }

        } catch (\Exception $e) {
            $this->error('❌ Failed to get queue status: ' . $e->getMessage());
        }
    }

    private function displayServiceHealth()
    {
        $this->newLine();
        $this->info('🏥 Service Health:');
        $this->info('==================');

        try {
            $servicesHealth = $this->healthChecker->checkAllServices();

            foreach ($servicesHealth as $serviceName => $service) {
                $status = $service['healthy'] ? '✅' : '❌';
                $responseTime = isset($service['response_time']) ? " ({$service['response_time']}ms)" : '';
                $this->info("{$status} {$serviceName}{$responseTime}");

                if (!$service['healthy'] && isset($service['error'])) {
                    $this->warn("   Error: {$service['error']}");
                }
            }

        } catch (\Exception $e) {
            $this->error('❌ Failed to get service health: ' . $e->getMessage());
        }
    }
}
