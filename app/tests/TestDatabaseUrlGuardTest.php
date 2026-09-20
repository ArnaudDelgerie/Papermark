<?php

namespace App\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TestDatabaseUrlGuardTest extends TestCase
{
    public static function legitimateUrlsProvider(): \Generator
    {
        $projectDir = \dirname(__DIR__);
        yield 'the forced test DSN, placeholder form' => ['sqlite:///%kernel.project_dir%/var/data_test.db'];
        yield 'the forced test DSN, expanded form' => ['sqlite:///'.$projectDir.'/var/data_test.db'];
        yield 'a nested file under var/' => ['sqlite:///'.$projectDir.'/var/cache/data_test.db'];
        yield 'a traversal that lands back under var/' => ['sqlite:///'.$projectDir.'/var/../var/data_test.db'];
    }

    public static function hostileUrlsProvider(): \Generator
    {
        $projectDir = \dirname(__DIR__);
        yield 'absolute path outside var/' => ['sqlite:///tmp/victim.db'];
        yield 'traversal escaping var/' => ['sqlite:///'.$projectDir.'/var/../../victim.db'];
        yield 'non-SQLite DSN (MySQL)' => ['mysql://user:password@localhost:3306/db'];
        yield 'non-SQLite DSN (PostgreSQL)' => ['postgresql://user@localhost/db'];
        yield 'SQLite with a host, so not a local file' => ['sqlite://localhost'.$projectDir.'/var/data_test.db'];
        yield 'relative path, resolved against the cwd' => ['sqlite://var/data_test.db'];
        yield 'in-memory, so not under var/' => ['sqlite://:memory:'];
        yield 'not a DSN' => ['not a url'];
    }

    #[DataProvider('legitimateUrlsProvider')]
    public function testAcceptsLegitimateUrls(string $databaseUrl): void
    {
        TestDatabaseUrlGuard::assert($databaseUrl);

        $this->addToAssertionCount(1); // no exception
    }

    #[DataProvider('hostileUrlsProvider')]
    public function testRejectsHostileUrls(string $databaseUrl): void
    {
        $this->expectException(\RuntimeException::class);

        TestDatabaseUrlGuard::assert($databaseUrl);
    }
}
