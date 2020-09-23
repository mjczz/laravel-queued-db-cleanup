<?php

namespace Spatie\LaravelQueuedDbCleanup\Tests;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Spatie\LaravelQueuedDbCleanup\CleanConfig;
use Spatie\LaravelQueuedDbCleanup\CleanDatabaseJobFactory;
use Spatie\LaravelQueuedDbCleanup\Events\CleanDatabaseCompleted;
use Spatie\LaravelQueuedDbCleanup\Events\CleanDatabasePassStarting;
use Spatie\LaravelQueuedDbCleanup\Exceptions\CouldNotCreateJob;
use Spatie\LaravelQueuedDbCleanup\Jobs\CleanDatabaseJob;
use Spatie\LaravelQueuedDbCleanup\Tests\TestClasses\InvalidDatabaseCleanupJobClass as InvalidDatabaseCleanupJobTestClass;
use Spatie\LaravelQueuedDbCleanup\Tests\TestClasses\TestModel;
use Spatie\LaravelQueuedDbCleanup\Tests\TestClasses\ValidDatabaseCleanupJobClass;
use Spatie\LaravelQueuedDbCleanup\Exceptions\InvalidDatabaseCleanupJobClass;

class CleansUpDatabaseTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        Event::fake();
    }

    /**
     * @test
     *
     * @dataProvider getTestCases
     */
    public function it_can_delete_records_in_the_right_amount_of_passes(
        int $totalRecords,
        int $chunkSize,
        int $remaining,
        int $passesPerformed
    ) {
        Event::fake();

        TestModel::factory()->count($totalRecords)->create();

        CleanDatabaseJobFactory::forQuery(TestModel::query())
            ->deleteChunkSize($chunkSize)
            ->dispatch();

        $this->assertEquals($remaining, TestModel::count());

        Event::assertDispatched(function (CleanDatabaseCompleted $event) use ($totalRecords, $passesPerformed) {
            $this->assertEquals($passesPerformed, $event->cleanConfig->pass);

            $this->assertEquals($totalRecords, $event->cleanConfig->totalRowsDeleted);

            return true;
        });
    }

    public function getTestCases(): array
    {
        return [
            [100, 10, 0, 11],
            [100, 10, 0, 11],
            [99, 10, 0, 10],
            [100, 5, 0, 21],
        ];
    }

    /** @test */
    public function it_can_continue_deleting_until_a_specified_condition()
    {
        TestModel::factory()->count(100)->create();

        CleanDatabaseJobFactory::new()
            ->query(TestModel::query())
            ->deleteChunkSize(10)
            ->stopWhen(function (CleanConfig $config) {
                return $config->pass === 3;
            })
            ->dispatch();

        $this->assertEquals(70, TestModel::count());

        Event::assertDispatched(function (CleanDatabaseCompleted $event) {
            $this->assertEquals(3, $event->cleanConfig->pass);

            $this->assertEquals(10, $event->cleanConfig->rowsDeletedInThisPass);
            $this->assertEquals(30, $event->cleanConfig->totalRowsDeleted);

            return true;
        });
    }

    /** @test */
    public function it_dispatches_a_start_event()
    {
        CleanDatabaseJobFactory::new()
            ->query(TestModel::query())
            ->deleteChunkSize(10)
            ->dispatch();

        Event::assertDispatched(function (CleanDatabasePassStarting $event) {
            $this->assertEquals(1, $event->cleanConfig->pass);

            return true;
        });
    }

    /** @test */
    public function it_will_not_clean_if_it_cannot_get_the_lock()
    {
        TestModel::factory()->count(10)->create();

        $job = CleanDatabaseJobFactory::new()
            ->query(TestModel::query())
            ->deleteChunkSize(10)
            ->getJob();

        $job->config->lock()->get();
        dispatch($job);
        $this->assertEquals(10, TestModel::count());

        $job->config->lock()->forceRelease();
        dispatch($job);
        $this->assertEquals(0, TestModel::count());
    }

    /** @test */
    public function the_job_can_be_serialized()
    {
        $job = CleanDatabaseJobFactory::new()
            ->query(TestModel::query())
            ->deleteChunkSize(10)
            ->getJob();

        $this->assertIsString(serialize($job));
    }

    /** @test */
    public function it_respects_the_bindings()
    {
        TestModel::factory()->count(10)->create();

        CleanDatabaseJobFactory::new()
            ->query(TestModel::query()->where('id', 1))
            ->deleteChunkSize(10)
            ->dispatch();

        $this->assertEquals(9, TestModel::count());
    }

    /** @test */
    public function it_can_use_a_custom_database_cleanup_job_class()
    {
        Bus::fake();

        CleanDatabaseJobFactory::new()
            ->query(TestModel::query())
            ->deleteChunkSize(10)
            ->useJobClass(ValidDatabaseCleanupJobClass::class)
            ->dispatch();

        Bus::assertDispatched(ValidDatabaseCleanupJobClass::class);
    }

    /** @test */
    public function the_default_database_cleanup_job_class_can_be_set()
    {
        Bus::fake();

        CleanDatabaseJobFactory::new()
            ->query(TestModel::query())
            ->deleteChunkSize(10)
            ->useJobClass(CleanDatabaseJob::class)
            ->dispatch();

        Bus::assertDispatched(CleanDatabaseJob::class);
    }

    /** @test */
    public function it_throws_an_exception_if_an_invalid_job_class_is_used()
    {
        $this->expectException(InvalidDatabaseCleanupJobClass::class);

        CleanDatabaseJobFactory::new()->useJobClass(InvalidDatabaseCleanupJobTestClass::class);
    }

    /** @test */
    public function it_throws_an_exception_if_no_query_was_set()
    {
        $this->expectException(CouldNotCreateJob::class);

        CleanDatabaseJobFactory::new()->dispatch();
    }

    /** @test */
    public function it_throws_an_exception_if_no_chunk_size_was_set()
    {
        $this->expectException(CouldNotCreateJob::class);

        CleanDatabaseJobFactory::new()->query(TestModel::query())->dispatch();
    }
}
