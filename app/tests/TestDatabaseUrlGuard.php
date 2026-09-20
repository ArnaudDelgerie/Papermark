<?php

namespace App\Tests;

use Doctrine\DBAL\Tools\DsnParser;
use Throwable;

/**
 * QUA-01: the test bootstrap drops the database designated by DATABASE_URL
 * before every run. A DATABASE_URL exported by the shell (another project, a
 * debug session on the hub's base) must never reach that drop.
 *
 * This lives as a class (autoloaded like any test helper) instead of inline in
 * tests/bootstrap.php so that a test can exercise it: phpunit.dist.xml forcing
 * the test DSN is a brace, with this guard as the belt underneath.
 *
 * The DSN is parsed by DBAL's own DsnParser, so the guard accepts exactly what
 * the connection would accept — including the "sqlite:///" + absolute-path form
 * the canonical DSN expands to ("sqlite:////home/..."), which parse_url alone
 * would choke on.
 */
final class TestDatabaseUrlGuard
{
    /**
     * Accepts only an SQLite DSN whose database file sits under the project's
     * var/ directory, and throws on anything else. Resolve-style placeholders
     * ("%kernel.project_dir%") are expanded the same way the kernel would.
     */
    public static function assert(string $databaseUrl): void
    {
        $projectDir = \dirname(__DIR__);
        $url = str_replace('%kernel.project_dir%', $projectDir, $databaseUrl);

        try {
            $params = (new DsnParser())->parse($url);
        } catch (Throwable $e) {
            throw new \RuntimeException(\sprintf('Refusing to run the tests: DATABASE_URL "%s" is not a parsable DSN (QUA-01).', $databaseUrl), 0, $e);
        }

        $driver = (string) ($params['driver'] ?? '');
        if (!str_contains($driver, 'sqlite')) {
            throw new \RuntimeException(\sprintf('Refusing to run the tests: DATABASE_URL "%s" resolves to the driver "%s", not SQLite (QUA-01).', $databaseUrl, $driver));
        }

        if (isset($params['memory']) || !isset($params['path'])) {
            throw new \RuntimeException(\sprintf('Refusing to run the tests: DATABASE_URL "%s" has no database file (QUA-01).', $databaseUrl));
        }

        $path = (string) $params['path'];
        if (!str_starts_with($path, '/')) {
            throw new \RuntimeException(\sprintf('Refusing to run the tests: DATABASE_URL "%s" points to the relative path "%s"; the test database must be absolute under var/ (QUA-01).', $databaseUrl, $path));
        }

        $varDir = $projectDir.'/var';
        $normalized = self::normalizePath($path);
        if (!str_starts_with($normalized, $varDir.'/')) {
            throw new \RuntimeException(\sprintf('Refusing to run the tests: DATABASE_URL "%s" points to "%s", outside "%s" (QUA-01: the bootstrap drops this database).', $databaseUrl, $normalized, $varDir));
        }
    }

    /**
     * Lexical normalization: resolves "." and ".." segments without requiring
     * the file to exist, so "var/../../victim.db" is seen as the escape it is.
     */
    private static function normalizePath(string $path): string
    {
        $normalized = '';
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                $normalized = \dirname($normalized);
                continue;
            }
            $normalized .= '/'.$segment;
        }

        return $normalized;
    }
}
