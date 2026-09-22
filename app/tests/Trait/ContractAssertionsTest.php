<?php

declare(strict_types=1);

namespace App\Tests\Trait;

use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;

final class ContractAssertionsTest extends TestCase
{
    use ContractAssertions;

    public function testKeysInAnyOrderPass(): void
    {
        $this->assertShapeMatches(['a' => 1, 'b' => 2], ['b' => 20, 'a' => 10], '$');
    }

    public function testValuesAreNeverCompared(): void
    {
        $this->assertShapeMatches(['a' => 'example text'], ['a' => 'a completely different value'], '$');
    }

    public function testAnExtraActualKeyFails(): void
    {
        $this->expectException(ExpectationFailedException::class);

        $this->assertShapeMatches(['a' => 1], ['a' => 1, 'b' => 2], '$');
    }

    public function testAMissingActualKeyFails(): void
    {
        $this->expectException(ExpectationFailedException::class);

        $this->assertShapeMatches(['a' => 1, 'b' => 2], ['a' => 1], '$');
    }

    public function testADifferentNestedKeyFails(): void
    {
        $this->expectException(ExpectationFailedException::class);

        $this->assertShapeMatches(['a' => ['x' => 1]], ['a' => ['y' => 1]], '$');
    }

    public function testEachListItemIsComparedToTheExamplesFirst(): void
    {
        $this->assertShapeMatches(['items' => [['x' => 1]]], ['items' => [['x' => 10], ['x' => 20]]], '$');
    }

    public function testAListItemMissingAKeyFails(): void
    {
        $this->expectException(ExpectationFailedException::class);

        $this->assertShapeMatches(['items' => [['x' => 1, 'y' => 2]]], ['items' => [['x' => 10]]], '$');
    }

    /** A write route's `action`: only "this is an object", never its keys. */
    public function testAnEmptyExampleAcceptsAnyArray(): void
    {
        $this->assertShapeMatches(['action' => []], ['action' => ['mode' => 'dir']], '$');
    }
}
