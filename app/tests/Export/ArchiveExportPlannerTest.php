<?php

declare(strict_types=1);

namespace App\Tests\Export;

use App\Export\ArchiveExportPlanner;
use App\Export\ExportEntry;
use App\Export\ExportIssueReason;
use App\File\MarkdownReferenceScanner;
use App\File\PathResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Validator\Validation;

final class ArchiveExportPlannerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/export_test_' . uniqid();
        mkdir($this->root, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testFileExportEmbedsRelativeImageExternally(): void
    {
        $this->write('doc.md', 'photo: ![alt](./img/photo.png)');
        $this->write('img/photo.png', 'PNG');

        $plan = $this->planner()->plan($this->root . '/doc.md', false);

        $doc = $this->entry($plan, 'doc.md');
        self::assertSame('photo: ![alt](ext_img/photo.png)', $doc->content);
        self::assertNotNull($this->findEntry($plan, 'ext_img/photo.png'));
        self::assertSame([], $plan->issues);
    }

    public function testFileExportEmbedsAbsoluteImageExternally(): void
    {
        $this->write('doc.md', "photo: ![alt]({$this->root}/img/photo.png)");
        $this->write('img/photo.png', 'PNG');

        $plan = $this->planner()->plan($this->root . '/doc.md', false);

        self::assertSame('photo: ![alt](ext_img/photo.png)', $this->entry($plan, 'doc.md')->content);
    }

    public function testDirectoryExportKeepsInternalRelativeImageUnchanged(): void
    {
        $this->write('doc.md', '![alt](./img/photo.png)');
        $this->write('img/photo.png', 'PNG');

        $plan = $this->planner()->plan($this->root, false);

        self::assertSame('![alt](./img/photo.png)', $this->entry($plan, 'doc.md')->content);
        self::assertNotNull($this->findEntry($plan, 'img/photo.png'));
    }

    public function testDirectoryExportRewritesInternalAbsoluteImageToRelative(): void
    {
        $this->write('doc.md', "![alt]({$this->root}/img/photo.png)");
        $this->write('img/photo.png', 'PNG');

        $plan = $this->planner()->plan($this->root, false);

        self::assertSame('![alt](img/photo.png)', $this->entry($plan, 'doc.md')->content);
    }

    public function testDirectoryExportRewritesLinkAcrossSubdirectories(): void
    {
        $this->write('a/doc.md', "[b]({$this->root}/b/other.md)");
        $this->write('b/other.md', 'other');

        $plan = $this->planner()->plan($this->root, false);

        self::assertSame('[b](../b/other.md)', $this->entry($plan, 'a/doc.md')->content);
    }

    public function testDirectoryExportMergesWithExistingExtImg(): void
    {
        $this->write('doc.md', '![alt](./ext_img/photo.png)');
        $this->write('ext_img/photo.png', 'EXISTING');

        $plan = $this->planner()->plan($this->root, false);

        self::assertSame('![alt](./ext_img/photo.png)', $this->entry($plan, 'doc.md')->content);
        self::assertNotNull($this->findEntry($plan, 'ext_img/photo.png'));
        self::assertCount(2, $plan->entries);
    }

    public function testDirectoryExportExternalCollisionWithExistingExtImgFileIsSuffixed(): void
    {
        $externalRoot = sys_get_temp_dir() . '/export_test_ext_' . uniqid();
        mkdir($externalRoot, 0o777, true);
        file_put_contents($externalRoot . '/photo.png', 'OUTSIDE');

        $this->write('doc.md', "![alt]({$externalRoot}/photo.png)");
        $this->write('ext_img/photo.png', 'EXISTING');

        try {
            $plan = $this->planner()->plan($this->root, false);

            self::assertSame('![alt](ext_img/photo (1).png)', $this->entry($plan, 'doc.md')->content);
            self::assertNotNull($this->findEntry($plan, 'ext_img/photo (1).png'));
        } finally {
            $this->removeDirectory($externalRoot);
        }
    }

    public function testDirectoryExportRefusesWhenExtImgIsAFile(): void
    {
        $this->write('doc.md', '# Hello');
        $this->write('ext_img', 'not a directory');

        $this->expectException(\App\Export\ArchiveExportRefusedException::class);

        $this->planner()->plan($this->root, false);
    }

    public function testExternalNameCollisionIsSuffixed(): void
    {
        $this->write('a/doc.md', '![a](../img_a/photo.png) ![b](../img_b/photo.png)');
        $this->write('img_a/photo.png', 'A');
        $this->write('img_b/photo.png', 'B');

        $plan = $this->planner()->plan($this->root . '/a/doc.md', false);

        self::assertSame(
            '![a](ext_img/photo.png) ![b](ext_img/photo (1).png)',
            $this->entry($plan, 'doc.md')->content,
        );
    }

    public function testExternalDuplicateReferenceIsDeduplicated(): void
    {
        $this->write('doc.md', '![a](./img/photo.png) ![b](./img/photo.png)');
        $this->write('img/photo.png', 'PNG');

        $plan = $this->planner()->plan($this->root . '/doc.md', false);

        $imageEntries = array_filter($plan->entries, static fn (ExportEntry $e): bool => str_starts_with($e->archivePath, 'ext_img/'));
        self::assertCount(1, $imageEntries);
    }

    public function testExternalMarkdownCycleIsEmbarkedOnceEach(): void
    {
        $this->write('root.md', '[a](a/a.md)');
        $this->write('a/a.md', '[b](../b/b.md)');
        $this->write('b/b.md', '[a](../a/a.md)');

        $plan = $this->planner()->plan($this->root . '/root.md', true);

        self::assertNotNull($this->findEntry($plan, 'ext_md/a.md'));
        self::assertNotNull($this->findEntry($plan, 'ext_md/b.md'));
        self::assertCount(3, $plan->entries);
        self::assertSame([], $plan->issues);
    }

    public function testExternalMarkdownLinkNotFollowedWhenCheckboxOff(): void
    {
        $this->write('root.md', '[a](a/a.md)');
        $this->write('a/a.md', 'content');

        $plan = $this->planner()->plan($this->root . '/root.md', false);

        self::assertSame('[a](a/a.md)', $this->entry($plan, 'root.md')->content);
        self::assertNull($this->findEntry($plan, 'ext_md/a.md'));
        self::assertSame([], $plan->issues);
    }

    public function testExternalMarkdownChainBeyondHopLimitIsReported(): void
    {
        $this->write('root.md', '[l1](l1/l1.md)');
        $this->write('l1/l1.md', '[l2](../l2/l2.md)');
        $this->write('l2/l2.md', '[l3](../l3/l3.md)');
        $this->write('l3/l3.md', '[l4](../l4/l4.md)');
        $this->write('l4/l4.md', 'too far');

        $plan = $this->planner(maxMarkdownHops: 3)->plan($this->root . '/root.md', true);

        self::assertNotNull($this->findEntry($plan, 'ext_md/l1.md'));
        self::assertNotNull($this->findEntry($plan, 'ext_md/l2.md'));
        self::assertNotNull($this->findEntry($plan, 'ext_md/l3.md'));
        self::assertNull($this->findEntry($plan, 'ext_md/l4.md'));
        self::assertSame('[l4](../l4/l4.md)', $this->entry($plan, 'ext_md/l3.md')->content);

        $issue = $plan->issues[0];
        self::assertSame(ExportIssueReason::LimitExceeded, $issue->reason);
        self::assertSame('../l4/l4.md', $issue->originalTarget);
    }

    public function testTotalFileCapReportsIssueOnceReached(): void
    {
        $this->write('root.md', '![a](./a.png) ![b](./b.png)');
        $this->write('a.png', 'A');
        $this->write('b.png', 'B');

        $plan = $this->planner(maxTotalFiles: 2)->plan($this->root . '/root.md', false);

        self::assertCount(2, $plan->entries); // root.md + a.png only
        self::assertNotNull($this->findEntry($plan, 'ext_img/a.png'));
        self::assertNull($this->findEntry($plan, 'ext_img/b.png'));
        self::assertSame(ExportIssueReason::LimitExceeded, $plan->issues[0]->reason);
    }

    public function testUnresolvableReferenceIsReportedAndLeftUnchanged(): void
    {
        $this->write('doc.md', '![alt](./missing.png)');

        $plan = $this->planner()->plan($this->root . '/doc.md', false);

        self::assertSame('![alt](./missing.png)', $this->entry($plan, 'doc.md')->content);
        self::assertSame(ExportIssueReason::NotFound, $plan->issues[0]->reason);
        self::assertSame('./missing.png', $plan->issues[0]->originalTarget);
    }

    public function testTxtFileIsEmbarkedButNotAnalyzed(): void
    {
        $this->write('doc.md', '[notes](./notes.txt)');
        $this->write('notes.txt', 'See ![alt](./photo.png) for more.');

        $plan = $this->planner()->plan($this->root . '/doc.md', false);

        $txtEntry = $this->findEntry($plan, 'ext_md/notes.txt');
        self::assertNotNull($txtEntry);
        self::assertNull($txtEntry->content);
        self::assertNull($this->findEntry($plan, 'ext_img/photo.png'));
    }

    public function testSchemeAndHtmlImgAreIgnored(): void
    {
        $this->write('doc.md', '![alt](https://example.com/a.png) <img src="b.png">');

        $plan = $this->planner()->plan($this->root . '/doc.md', false);

        self::assertSame('![alt](https://example.com/a.png) <img src="b.png">', $this->entry($plan, 'doc.md')->content);
        self::assertSame([], $plan->issues);
        self::assertCount(1, $plan->entries);
    }

    public function testDirectoryExportDiscoversMarkdownExtensionFileOnlyThroughALink(): void
    {
        // The walk (like DirectoryTree) only sweeps .md files, so notes.markdown
        // is found through the link, registered internal, and scanned in turn.
        $this->write('doc.md', '[notes](./notes.markdown)');
        $this->write('notes.markdown', '![alt](./img/photo.png)');
        $this->write('img/photo.png', 'PNG');

        $plan = $this->planner()->plan($this->root, false);

        self::assertSame('[notes](./notes.markdown)', $this->entry($plan, 'doc.md')->content);
        self::assertSame('![alt](./img/photo.png)', $this->entry($plan, 'notes.markdown')->content);
        self::assertNotNull($this->findEntry($plan, 'img/photo.png'));
    }

    public function testAnchorIsPreservedOnRewrittenLink(): void
    {
        $this->write('doc.md', "[intro]({$this->root}/notes.md#intro)");
        $this->write('notes.md', 'notes');

        $plan = $this->planner()->plan($this->root . '/doc.md', true);

        self::assertSame('[intro](ext_md/notes.md#intro)', $this->entry($plan, 'doc.md')->content);
    }

    private function planner(int $maxTotalFiles = 500, int $maxMarkdownHops = 3): ArchiveExportPlanner
    {
        return new ArchiveExportPlanner(
            new MarkdownReferenceScanner(),
            new PathResolver(new Filesystem(), Validation::createValidator()),
            new Filesystem(),
            $maxTotalFiles,
            $maxMarkdownHops,
        );
    }

    private function write(string $relativePath, string $content): void
    {
        $path = $this->root . '/' . $relativePath;
        @mkdir(\dirname($path), 0o777, true);
        file_put_contents($path, $content);
    }

    private function entry(\App\Export\ExportPlan $plan, string $archivePath): ExportEntry
    {
        $entry = $this->findEntry($plan, $archivePath);
        self::assertNotNull($entry, "No entry for {$archivePath}");

        return $entry;
    }

    private function findEntry(\App\Export\ExportPlan $plan, string $archivePath): ?ExportEntry
    {
        foreach ($plan->entries as $entry) {
            if ($entry->archivePath === $archivePath) {
                return $entry;
            }
        }

        return null;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
