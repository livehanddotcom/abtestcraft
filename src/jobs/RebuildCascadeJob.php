<?php

declare(strict_types=1);

namespace livehand\abtestcraft\jobs;

use Craft;
use craft\queue\BaseJob;
use livehand\abtestcraft\ABTestCraft;

/**
 * Queue job for rebuilding cascade descendant mappings asynchronously
 * Used when a test has more than 50 descendants to avoid timeouts
 */
class RebuildCascadeJob extends BaseJob
{
    /**
     * @var int The test ID to rebuild descendants for
     */
    public int $testId;

    /**
     * @var bool Clear the test's page cache after rebuilding. Set when a test
     * starts: child pages only count as "under test" once mapped, so Blitz may
     * have cached some of them while this job waited in the queue.
     */
    public bool $clearPageCache = false;

    /**
     * Execute the job
     */
    public function execute($queue): void
    {
        $test = ABTestCraft::getInstance()->tests->getTestById($this->testId);

        if (!$test) {
            Craft::warning(
                "RebuildCascadeJob: Test {$this->testId} not found, skipping",
                __METHOD__
            );
            return;
        }

        $result = ABTestCraft::getInstance()->cascade->doRebuildDescendants($test);

        if ($result && $this->clearPageCache && $test->isRunning()) {
            ABTestCraft::getInstance()->pageCache->clearTestPages($test);
        }

        if ($result) {
            Craft::info(
                "RebuildCascadeJob: Successfully rebuilt descendants for test '{$test->handle}'",
                __METHOD__
            );
        } else {
            Craft::error(
                "RebuildCascadeJob: Failed to rebuild descendants for test '{$test->handle}'",
                __METHOD__
            );
        }
    }

    /**
     * Get the job description for the queue manager
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('abtestcraft', 'Rebuilding A/B test cascade mappings');
    }
}
