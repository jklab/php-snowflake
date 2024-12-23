<?php

declare(strict_types=1);

/*
 * This file is part of the godruoyi/php-snowflake.
 *
 * (c) Godruoyi <g@godruoyi.com>
 *
 * This source file is subject to the MIT license that is bundled.
 */

namespace Tests;

use Godruoyi\Snowflake\FileLockResolver;
use Godruoyi\Snowflake\PredisSequenceResolver;
use Godruoyi\Snowflake\RedisSequenceResolver;
use Godruoyi\Snowflake\Snowflake;
use Predis\Client;
use Throwable;

class BatchSnowflakeIDTest extends TestCase
{
    public function test_batch_for_same_instance_with_default_driver(): void
    {
        $ids = [];
        $count = 100000;
        $snowflake = new Snowflake();

        for ($i = 0; $i < $count; $i++) {
            $id = $snowflake->id();
            $ids[$id] = 1;
        }

        $this->assertCount($count, $ids);
    }

    /**
     * @throws Throwable
     */
    public function test_batch_for_diff_instance_with_redis_driver(): void
    {
        if (! extension_loaded('redis')
            || ! getenv('REDIS_HOST')
            || ! getenv('REDIS_PORT')) {
            $this->markTestSkipped('Redis extension is not installed or not configured.');
        }

        if (! extension_loaded('pcntl')) {
            $this->markTestSkipped('The pcntl extension is not installed.');
        }

        $results = $this->parallelRun(function () {
            $redis = new \Redis();
            $redis->connect(getenv('REDIS_HOST'), getenv('REDIS_PORT') | 0);

            return new RedisSequenceResolver($redis);
        }, 100, 1000);

        // Should generate 100k unique IDs
        $this->assertResults($results, 100, 1000);
    }

    public function test_batch_for_diff_instance_with_predis_driver(): void
    {
        if (! class_exists('Predis\\Client')
            || ! getenv('REDIS_HOST')
            || ! getenv('REDIS_PORT')) {
            $this->markTestSkipped('Redis extension is not installed or not configured.');
        }

        if (! extension_loaded('pcntl')) {
            $this->markTestSkipped('The pcntl extension is not installed.');
        }

        $results = $this->parallelRun(function () {
            $client = new Client([
                'scheme' => 'tcp',
                'host' => getenv('REDIS_HOST'),
                'port' => getenv('REDIS_PORT') | 0,
            ]);

            $client->ping();

            return new PredisSequenceResolver($client);
        }, 100, 1000);

        // Should generate 100k unique IDs
        $this->assertResults($results, 100, 1000);
    }

    /**
     * @throws Throwable
     */
    public function test_batch_for_diff_instance_with_file_driver(): void
    {
        $fileResolver = new FileLockResolver(__DIR__);

        $results = $this->parallelRun(function () use ($fileResolver) {
            return $fileResolver;
        }, 100, 1000);

        // Should generate 100k unique IDs
        $this->assertResults($results, 100, 1000);

        $fileResolver->cleanAllLocksFile();
    }

    /**
     * Runs the given function in parallel using the specified number of processes.
     *
     * @param  int  $parallel  The number of processes to run in parallel.
     * @param  int  $count  The number of times to run the function.
     *
     * @throws Throwable
     */
    protected function parallelRun(callable $resolver, int $parallel, int $count): array
    {
        return Support\Parallel::run(function () use ($resolver, $count) {
            $snowflake = (new Snowflake(0, 0))
                ->setSequenceResolver($resolver())
                ->setStartTimeStamp(strtotime('2022-12-14') * 1000);

            $ids = [];
            for ($i = 0; $i < $count; $i++) {
                $ids[] = $snowflake->id();
            }

            return $ids;
        }, $parallel);
    }

    /**
     * Asserts the results of a parallel execution.
     *
     * @param  array  $results  The array of results.
     * @param  int  $parallel  The number of parallel executions.
     * @param  int  $count  The expected count for each execution.
     */
    private function assertResults(array $results, int $parallel, int $count): void
    {
        $this->assertCount($parallel, $results);

        $ids = [];
        foreach ($results as $result) {
            $this->assertArrayHasKey('data', $result);
            $this->assertArrayHasKey('error', $result);
            if ($result['error']) {
                $this->fail($result['error']);
            }
            $this->assertCount($count, $result['data']);
            $this->assertNull($result['error']);

            foreach ($result['data'] as $id) {
                $ids[$id] = 1;
            }
        }

        $this->assertCount($parallel * $count, $ids);
    }
}
