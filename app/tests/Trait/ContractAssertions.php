<?php

declare(strict_types=1);

namespace App\Tests\Trait;

/**
 * The shared shape between server and front (CODE_REVIEW_3, lot 1): every
 * example under tests/contract/ is read here and compared to what a
 * controller actually rendered. Only the keys are checked, recursively —
 * the values are free to be whatever reads best in the example. A list
 * compares each of its actual items to the example's first one.
 */
trait ContractAssertions
{
    /**
     * @param array<mixed> $actual
     */
    protected function assertMatchesContract(string $name, array $actual): void
    {
        $path = \dirname(__DIR__) . '/contract/' . $name . '.json';
        self::assertFileExists($path, "Missing contract example: {$path}");

        $expected = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($expected, "{$name}.json must decode to an array");

        $this->assertShapeMatches($expected, $actual, $name);
    }

    /**
     * @param mixed $expected
     * @param mixed $actual
     */
    protected function assertShapeMatches(mixed $expected, mixed $actual, string $path): void
    {
        if (!\is_array($expected)) {
            // Values are never compared: a scalar or null example says only
            // "this key exists", whatever it holds.
            return;
        }

        self::assertIsArray($actual, "{$path}: expected an array, got " . get_debug_type($actual));

        if (array_is_list($expected)) {
            // An empty example, list or object alike (json_decode can't tell
            // them apart), says only "this is an array": exactly what the
            // one untyped shape here needs — a write route's `action`.
            if ($expected === [] || $actual === []) {
                return;
            }

            foreach ($actual as $index => $item) {
                $this->assertShapeMatches($expected[0], $item, "{$path}[{$index}]");
            }

            return;
        }

        self::assertEqualsCanonicalizing(
            array_keys($expected),
            array_keys($actual),
            "{$path}: keys differ from the example",
        );

        foreach ($expected as $key => $value) {
            $this->assertShapeMatches($value, $actual[$key], "{$path}.{$key}");
        }
    }
}
