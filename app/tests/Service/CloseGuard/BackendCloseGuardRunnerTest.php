<?php

declare(strict_types=1);

namespace App\Tests\Service\CloseGuard;

use App\Service\CloseGuard\BackendCloseGuardRunner;
use App\Tests\Double\RecordingCloseGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\ErrorHandler\BufferingLogger;

final class BackendCloseGuardRunnerTest extends TestCase
{
    private RecordingCloseGuard $guards;

    private BufferingLogger $logger;

    private ?\DomainException $workBoom = null;

    protected function setUp(): void
    {
        $this->guards = new RecordingCloseGuard();
        $this->logger = new BufferingLogger();
        $this->workBoom = null;
    }

    /**
     * The work of the throwing tests: raises the prepared exception, or
     * returns when none was. A plain `throw` inline would let phpstan
     * infer the closure never returns and flag the rest of the test
     * unreachable.
     */
    private function guardedWork(): string
    {
        if ($this->workBoom !== null) {
            throw $this->workBoom;
        }

        return 'the result';
    }

    public function testRegistersBeforeTheWorkRemovesAfterAndReturnsTheWorkValue(): void
    {
        $callsSeenByTheWork = null;

        $result = $this->makeRunner()->run('export:deadbeef', function () use (&$callsSeenByTheWork): string {
            $callsSeenByTheWork = $this->guards->calls;

            return 'the result';
        });

        self::assertSame('the result', $result);
        self::assertSame([['register', 'export:deadbeef']], $callsSeenByTheWork);
        self::assertSame([
            ['register', 'export:deadbeef'],
            ['remove', 'export:deadbeef'],
        ], $this->guards->calls);
        self::assertSame([], $this->logger->cleanLogs());
    }

    public function testAWorkThatThrowsIsRemovedAnywayAndTheExceptionSurfacesAsIs(): void
    {
        $this->workBoom = new \DomainException('the work failed');
        $caught = null;

        try {
            $this->makeRunner()->run('import:cafe', fn (): string => $this->guardedWork());
        } catch (\DomainException $e) {
            $caught = $e;
        }

        self::assertSame('the work failed', $caught?->getMessage());

        self::assertSame([
            ['register', 'import:cafe'],
            ['remove', 'import:cafe'],
        ], $this->guards->calls);
        self::assertSame([], $this->logger->cleanLogs());
    }

    public function testAGuardTheBridgeRefusedRunsTheWorkWithoutRemove(): void
    {
        $this->guards->available = false;
        $workRan = false;

        $result = $this->makeRunner()->run('export:1', function () use (&$workRan): string {
            $workRan = true;

            return 'the result';
        });

        self::assertSame('the result', $result);
        self::assertTrue($workRan);
        self::assertSame([['register', 'export:1']], $this->guards->calls);
        self::assertSame([], $this->logger->cleanLogs());
    }

    public function testARegisterFailureIsLoggedAndTheWorkRunsUnguarded(): void
    {
        $this->guards->throwsOnRegister = true;
        $workRan = false;

        $result = $this->makeRunner()->run('import:1', function () use (&$workRan): string {
            $workRan = true;

            return 'the result';
        });

        self::assertSame('the result', $result);
        self::assertTrue($workRan);
        self::assertSame([['register', 'import:1']], $this->guards->calls);
        $logs = $this->logger->cleanLogs();
        self::assertCount(1, $logs);
        self::assertSame('warning', $logs[0][0]);
        self::assertSame('Close guard {id} could not be registered: {message}', $logs[0][1]);
        self::assertSame('import:1', $logs[0][2]['id']);
        self::assertInstanceOf(\Throwable::class, $logs[0][2]['exception']);
    }

    public function testARemoveFailureIsLoggedAndSwallowed(): void
    {
        $this->guards->throwsOnRemove = true;

        $result = $this->makeRunner()->run('export:2', function (): string {
            return 'the result';
        });

        self::assertSame('the result', $result);
        self::assertSame([
            ['register', 'export:2'],
            ['remove', 'export:2'],
        ], $this->guards->calls);
        $logs = $this->logger->cleanLogs();
        self::assertCount(1, $logs);
        self::assertSame('warning', $logs[0][0]);
        self::assertSame('Close guard {id} could not be removed: {message}', $logs[0][1]);
        self::assertSame('export:2', $logs[0][2]['id']);
        self::assertInstanceOf(\Throwable::class, $logs[0][2]['exception']);
    }

    public function testAWorkThatThrowsUnderAFailingRemoveStillSurfacesTheWorkException(): void
    {
        $this->guards->throwsOnRemove = true;
        $this->workBoom = new \DomainException('the work failed');
        $caught = null;

        try {
            $this->makeRunner()->run('import:2', fn (): string => $this->guardedWork());
        } catch (\DomainException $e) {
            $caught = $e;
        }

        self::assertSame('the work failed', $caught?->getMessage());

        self::assertCount(1, $this->logger->cleanLogs());
    }

    private function makeRunner(): BackendCloseGuardRunner
    {
        return new BackendCloseGuardRunner($this->guards, $this->logger);
    }
}
