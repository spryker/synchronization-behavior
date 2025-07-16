<?php

/**
 * Copyright 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Zed\SynchronizationBehavior\Communication\Console;

use Orm\Zed\Product\Persistence\SpyProductAbstractLocalizedAttributesQuery;
use Orm\Zed\Product\Persistence\SpyProductAbstractQuery;
use Orm\Zed\Store\Persistence\SpyStoreQuery;
use Orm\Zed\Locale\Persistence\SpyLocaleQuery;
use Spryker\Zed\Kernel\Communication\Console\Console;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use Propel\Runtime\ActiveQuery\Criteria;

/**
 * @method \Spryker\Zed\SynchronizationBehavior\SynchronizationBehaviorConfig getConfig()
 */
class SyncQueueOptimizationValidateConsole extends Console
{
    /**
     * @var string
     */
    protected const COMMAND_NAME = 'sync:queue:optimization:validate';

    /**
     * @var string
     */
    protected const COMMAND_DESCRIPTION = 'Validates the sync queue optimization by measuring performance';

    /**
     * @var string
     */
    protected const OPTION_RESULTS_FILE = 'results-file';

    /**
     * @var string
     */
    protected const OPTION_RESULTS_FILE_SHORT = 'r';

    protected const OPTION_LIMIT = 'limit';

    /**
     * @var string
     */
    protected $resultsFile;

    /**
     * @var int
     */
    protected $productLimit;

    /**
     * @var \Symfony\Component\Console\Output\OutputInterface
     */
    protected $output;

    /**
     * @return void
     */
    protected function configure(): void
    {
        parent::configure();

        $this->setName(static::COMMAND_NAME)
            ->setDescription(static::COMMAND_DESCRIPTION)
            ->addOption(
                static::OPTION_RESULTS_FILE,
                static::OPTION_RESULTS_FILE_SHORT,
                InputOption::VALUE_OPTIONAL,
                'Path to save results file',
                APPLICATION_ROOT_DIR . '/data/sync_queue_optimization_results.json'
            )
            ->addOption(
                static::OPTION_LIMIT,
                static::OPTION_LIMIT,
                InputOption::VALUE_OPTIONAL,
                'Limit the number of items to validate',
                100000
            );;
    }

    /**
     * {@inheritDoc}
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->output = $output;

        $this->initializeTest($input);

        $this->output->writeln('<info>Starting sync queue optimization validation...</info>');

        try {
            // Run the tests
            $results = $this->runValidationTest();

            // Save results
            $resultsFile = $this->resultsFile;
            $this->saveResults($results, $resultsFile);

            return static::CODE_SUCCESS;
        } catch (\Exception $e) {
            $this->output->writeln(sprintf('<error>Error: %s</error>', $e->getMessage()));
            return static::CODE_ERROR;
        }
    }

    /**
     * Initialize test configuration
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input
     *
     * @return void
     */
    protected function initializeTest(InputInterface $input): void
    {
        // Set results file path
        $this->resultsFile = $input->getOption(static::OPTION_RESULTS_FILE);

        // Set product limit
        $this->productLimit = $input->getOption(static::OPTION_LIMIT);

        // Setup test stores and locales from actual database
        $storeEntities = SpyStoreQuery::create()->find();
        foreach ($storeEntities as $storeEntity) {
            $localeEntities = SpyLocaleQuery::create()
                ->joinLocaleStore()
                ->useLocaleStoreQuery()
                // ->useSpyLocaleStoreQuery()
                    ->filterByFkStore($storeEntity->getIdStore())
                ->endUse()
                ->find();

            $locales = [];
            foreach ($localeEntities as $localeEntity) {
                $locales[] = $localeEntity->getLocaleName();
            }
        }
    }

    /**
     * Run the validation test focused on performance measurement
     *
     * @return array
     */
    protected function runValidationTest(): array
    {
        $this->output->writeln('<info>Starting sync queue performance test...</info>');

        $totalProductsUpdated = $this->updateProductsInBatches();

        if ($totalProductsUpdated === 0) {
            throw new \Exception('No products found for testing');
        }

        $this->output->writeln(sprintf('<info>Updated %d products total</info>', $totalProductsUpdated));

        // Measure queue processing performance
        $performanceResults = $this->measureQueueProcessingPerformance();

        return [
            'products_updated' => $totalProductsUpdated,
            'performance' => $performanceResults
        ];
    }

    protected function updateProductsInBatches(): int
    {
        $batchSize = 1000; // Process 1000 products at a time
        $offset = 0;
        $totalUpdated = 0;

        $this->output->writeln(sprintf('<info>Processing up to %d products in batches of %d...</info>', $this->productLimit, $batchSize));

        while ($totalUpdated < $this->productLimit) {
            $remainingLimit = $this->productLimit - $totalUpdated;
            $currentBatchSize = min($batchSize, $remainingLimit);

            // Get batch of products
            $productIds = $this->getProductIdsBatch($offset, $currentBatchSize);

            if (empty($productIds)) {
                $this->output->writeln('<info>No more products found</info>');
                break;
            }

            // Update products in current batch
            $updatedInBatch = $this->updateProductBatch($productIds);
            $totalUpdated += $updatedInBatch;

            $this->output->writeln(sprintf('<info>Batch processed: %d products (Total: %d)</info>', $updatedInBatch, $totalUpdated));

            $offset += $currentBatchSize;

            // Clear memory
            gc_collect_cycles();
        }

        return $totalUpdated;
    }

    protected function getProductIdsBatch(int $offset, int $limit): array
    {
        $productAbstracts = SpyProductAbstractQuery::create()
            ->useSpyProductAbstractLocalizedAttributesQuery()
            ->filterByFkLocale(46) // German locale ID
            ->endUse()
            ->offset($offset)
            ->limit($limit)
            ->select(['IdProductAbstract'])
            ->find();

        return $productAbstracts->toArray();
    }

    protected function updateProductBatch(array $productIds): int
    {
        $updated = 0;

        foreach ($productIds as $productId) {
            $this->updateProductTitle($productId);
            $updated++;
        }

        return $updated;
    }

    /**
     * Save results to a file
     *
     * @param array $results
     * @param string $resultsFile
     *
     * @return void
     */
    protected function saveResults(array $results, string $resultsFile): void
    {
        $this->output->writeln(sprintf('<info>Saving results...</info>'));

        // Create directory if it doesn't exist
        $dataDir = dirname($resultsFile);
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0755, true);
        }

        // Save results
        file_put_contents($resultsFile, json_encode($results, JSON_PRETTY_PRINT));

        $this->output->writeln(sprintf('<info>Results saved to %s</info>', $resultsFile));
    }

    /**
     * Update product title for first available locale
     *
     * @param int $idProductAbstract
     *
     * @return void
     */
    protected function updateProductTitle(int $idProductAbstract): void
    {
        // Find first available localized attribute
        $localizedAttr = SpyProductAbstractLocalizedAttributesQuery::create()
            ->filterByFkProductAbstract($idProductAbstract)
            ->filterByFkLocale(46)
            ->findOne();

        if (!$localizedAttr) {
            return;
        }

        // Update the title
        $currentTitle = $localizedAttr->getName() ?? 'Untitled Product';
        $newTitle = $currentTitle . ' - Updated ' . date('H:i:s');

        try {
            $localizedAttr->setName($newTitle);
            $localizedAttr->save();
        } catch (\Exception $e) {
//            $this->output->writeln(sprintf('<comment>Could not update product %d: %s</comment>', $idProductAbstract, $e->getMessage()));
        }
    }

    /**
     * Measure queue processing performance with external process monitoring
     *
     * @return array
     */
    protected function measureQueueProcessingPerformance(): array
    {
        $this->output->writeln('<info>Measuring queue processing performance...</info>');

        // Start time tracking
        $startTime = microtime(true);

        // Start queue worker monitoring in background
        $memoryLog = tempnam(sys_get_temp_dir(), 'queue_worker_memory_');
        $monitoringPid = $this->startMemoryMonitoring($memoryLog);

        // Execute queue worker
        $queueOutput = $this->executeQueueWorker();

        // End time tracking
        $endTime = microtime(true);

        // Stop monitoring
        if ($monitoringPid) {
            exec("kill {$monitoringPid} 2>/dev/null");
        }

        // Read memory statistics
        $memoryStats = $this->parseMemoryLog($memoryLog);
        unlink($memoryLog);

        // Calculate metrics
        $executionTime = $endTime - $startTime;

        $performanceResults = [
            'execution_time_seconds' => round($executionTime, 3),
            'worker_memory_used_mb' => $memoryStats['peak_memory_mb'],
            'worker_avg_memory_mb' => $memoryStats['avg_memory_mb'],
            'start_time' => date('Y-m-d H:i:s', (int)$startTime),
            'end_time' => date('Y-m-d H:i:s', (int)$endTime),
            'queue_output' => $queueOutput
        ];

        return $performanceResults;
    }

    /**
     * Start external memory monitoring of queue worker processes
     *
     * @param string $logFile
     *
     * @return int|null Process ID of monitoring script
     */
    protected function startMemoryMonitoring(string $logFile): ?int
    {
        $monitorScript = sprintf(
            'while true; do pgrep -f "queue:worker:start" | xargs -I {} ps -o pid,rss {} 2>/dev/null | grep -v PID >> %s; sleep 0.1; done',
            $logFile
        );

        $cmd = "nohup bash -c '{$monitorScript}' > /dev/null 2>&1 & echo $!";
        $pid = trim(shell_exec($cmd));

        return $pid ? (int)$pid : null;
    }

    /**
     * Parse memory usage log file
     *
     * @param string $logFile
     *
     * @return array
     */
    protected function parseMemoryLog(string $logFile): array
    {
        if (!file_exists($logFile)) {
            return ['peak_memory_mb' => 0, 'avg_memory_mb' => 0];
        }

        $content = file_get_contents($logFile);
        if (empty($content)) {
            return ['peak_memory_mb' => 0, 'avg_memory_mb' => 0];
        }

        $lines = explode("\n", trim($content));
        $memoryValues = [];

        foreach ($lines as $line) {
            if (preg_match('/\s+(\d+)\s+(\d+)/', $line, $matches)) {
                $memoryKb = (int)$matches[2];
                $memoryValues[] = $memoryKb;
            }
        }

        if (empty($memoryValues)) {
            return ['peak_memory_mb' => 0, 'avg_memory_mb' => 0];
        }

        $peakMemoryKb = max($memoryValues);
        $avgMemoryKb = array_sum($memoryValues) / count($memoryValues);

        return [
            'peak_memory_mb' => round($peakMemoryKb / 1024, 2),
            'avg_memory_mb' => round($avgMemoryKb / 1024, 2)
        ];
    }

    /**
     * Execute queue worker and capture output
     *
     * @return string
     */
    protected function executeQueueWorker(): string
    {
        $this->output->writeln('<comment>Executing queue:worker:start...</comment>');

        try {
            $command = $this->getApplication()->find('queue:worker:start');
            $bufferedOutput = new BufferedOutput();

            $arguments = new ArrayInput([
                'command' => 'queue:worker:start',
                '--stop-when-empty' => true,
            ]);

            $returnCode = $command->run($arguments, $bufferedOutput);
            $output = $bufferedOutput->fetch();

            $this->output->writeln(sprintf('<info>Queue worker completed with return code: %d</info>', $returnCode));

            return $output;

        } catch (\Exception $e) {
            $errorMsg = sprintf('Queue worker execution failed: %s', $e->getMessage());
            $this->output->writeln(sprintf('<error>%s</error>', $errorMsg));
            return $errorMsg;
        }
    }
}
