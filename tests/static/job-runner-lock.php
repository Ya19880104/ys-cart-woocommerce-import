<?php
declare(strict_types=1);

$runner = (string)file_get_contents(__DIR__ . '/../../src/Jobs/JobRunner.php');

foreach (['acquireJobLock', 'releaseJobLock', 'lockKey'] as $method) {
    if (strpos($runner, 'function ' . $method) === false) {
        throw new RuntimeException("JobRunner must define {$method}().");
    }
}

if (strpos($runner, '!$this->acquireJobLock($jobId)') === false
    || strpos($runner, '$this->releaseJobLock($jobId);') === false) {
    throw new RuntimeException('JobRunner::runNext() must wrap dispatch with a per-job lock.');
}

if (strpos($runner, "ys_cwci_job_lock_' . \$jobId") === false) {
    throw new RuntimeException('JobRunner lock key must be scoped to the migration job id.');
}
