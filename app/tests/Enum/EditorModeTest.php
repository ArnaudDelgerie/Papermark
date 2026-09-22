<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\Setting\EditorMode;
use PHPUnit\Framework\TestCase;

final class EditorModeTest extends TestCase
{
    public function testDirExportsAsDirectory(): void
    {
        self::assertSame('directory', EditorMode::Dir->exportKind());
    }

    public function testSingleExportsAsFile(): void
    {
        self::assertSame('file', EditorMode::Single->exportKind());
    }
}
