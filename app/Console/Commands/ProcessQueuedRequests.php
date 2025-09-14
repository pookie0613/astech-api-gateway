<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\MicroserviceProxy;
use Illuminate\Support\Facades\Log;

class ProcessQueuedRequests extends Command
{
    protected $signature = 'queue:process
                            {--continuous : Process continuously}
                            {--interval=5 : Interval between processing cycles in seconds}
                            {--max-cycles=0 : Maximum number of processing cycles (0 = unlimited)}';

    protected $description = 'Process queued requests from Redis queues';

    private $proxy;
    private $cycleCount = 0;

    public function __construct(MicroserviceProxy $proxy)
    {
        parent::__construct();
        $this->proxy = $proxy;
    }

    public function handle()
    {
        $continuous = $this->option('continuous');
        $interval = (int) $this->option('interval');
        $maxCycles = (int) $this->option('max-cycles');

        $this->info('🚀 Starting Queue Request Processor');
        $this->info('Mode: ' . ($continuous ? 'Continuous' : 'Single run'));
        $this->info('Interval: ' . $interval . ' seconds');
        $this->info('Max Cycles: ' . ($maxCycles > 0 ? $maxCycles : 'Unlimited'));

        if ($continuous) {
            $this->info('Press Ctrl+C to stop processing');
            $this->runContinuous($interval, $maxCycles);
        } else {
            $this->runOnce();
        }

        $this->displayFinalStats();
        return 0;
    }

    private function runOnce()
    {
        try {
            $this->info('🔄 Processing queued requests...');
            $result = $this->proxy->processQueuedRequests();

            if ($result) {
                $this->info('✅ Queued requests processed successfully');
            } else {
                $this->warn('⚠️ No queued requests to process or processing failed');
            }
        } catch (\Exception $e) {
            $this->error('❌ Error processing queued requests: ' . $e->getMessage());
            Log::error('Error processing queued requests', ['error' => $e->getMessage()]);
        }
    }

    private function runContinuous($interval, $maxCycles)
    {
        while (true) {
            try {
                $this->cycleCount++;

                if ($maxCycles > 0 && $this->cycleCount >= $maxCycles) {
                    $this->info("🛑 Maximum cycles ({$maxCycles}) reached. Stopping.");
                    break;
                }

                $this->info("🔄 Processing cycle #{$this->cycleCount} at " . now()->format('H:i:s'));

                $result = $this->proxy->processQueuedRequests();

                if ($result) {
                    $this->info('✅ Queued requests processed successfully');
                } else {
                    $this->warn('⚠️ No queued requests to process or processing failed');
                }

                $this->displayCycleStats();

                if ($interval > 0) {
                    $this->info("⏳ Waiting {$interval} seconds before next cycle...");
                    sleep($interval);
                }

            } catch (\Exception $e) {
                $this->error('❌ Error in processing cycle: ' . $e->getMessage());
                Log::error('Error in processing cycle', ['error' => $e->getMessage()]);

                if ($interval > 0) {
                    sleep($interval);
                }
            }
        }
    }

    private function displayCycleStats()
    {
        $this->newLine();
        $this->info('📊 Cycle Statistics:');
        $this->info('====================');
        $this->info("Cycle: {$this->cycleCount}");
        $this->info("Time: " . now()->format('H:i:s'));

        try {
            $queueStatus = $this->proxy->getQueueStatus();
            if (isset($queueStatus['request_queue']['message_count'])) {
                $this->info("Remaining in queue: {$queueStatus['request_queue']['message_count']}");
            }
        } catch (\Exception $e) {
            $this->warn('⚠️ Could not get queue status');
        }
    }

    private function displayFinalStats()
    {
        $this->newLine();
        $this->info('🏁 Final Statistics:');
        $this->info('===================');
        $this->info("Total cycles: {$this->cycleCount}");
        $this->info("Completed at: " . now()->format('Y-m-d H:i:s'));

        try {
            $queueStatus = $this->proxy->getQueueStatus();
            if (isset($queueStatus['request_queue']['message_count'])) {
                $this->info("Final queue size: {$queueStatus['request_queue']['message_count']}");
            }
        } catch (\Exception $e) {
            $this->warn('⚠️ Could not get final queue status');
        }
    }
}
