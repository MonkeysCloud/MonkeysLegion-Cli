<?php

declare(strict_types=1);

namespace MonkeysLegion\Cli\Tests\Unit\Console;

use MonkeysLegion\Cli\Console\Command;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the InteractsWithFiles trait.
 *
 * @covers \MonkeysLegion\Cli\Console\Traits\InteractsWithFiles
 */
final class InteractsWithFilesTest extends TestCase
{
    private string $testDir;
    private FileTestCommand $command;

    protected function setUp(): void
    {
        parent::setUp();
        // Create a temporary test directory inside the workspace/tests directory
        $this->testDir = __DIR__ . DIRECTORY_SEPARATOR . 'tmp_file_operations_test';
        if (!is_dir($this->testDir)) {
            mkdir($this->testDir, 0755, true);
        }
        $this->command = new FileTestCommand();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->testDir);
        parent::tearDown();
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            if (file_exists($path)) {
                unlink($path);
            }
            return;
        }

        $files = array_diff(scandir($path) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $currPath = $path . DIRECTORY_SEPARATOR . $file;
            if (is_dir($currPath)) {
                $this->removeDirectory($currPath);
            } else {
                unlink($currPath);
            }
        }
        rmdir($path);
    }

    public function testWriteAndReadFile(): void
    {
        $filePath = $this->testDir . DIRECTORY_SEPARATOR . 'test.txt';
        $content = 'Hello World!';

        $this->assertTrue($this->command->callWriteFile($filePath, $content));
        $this->assertSame($content, $this->command->callReadFile($filePath));
    }

    public function testReadFileReturnsNullOnNonExistentFile(): void
    {
        $filePath = $this->testDir . DIRECTORY_SEPARATOR . 'missing.txt';
        $this->assertNull($this->command->callReadFile($filePath));
    }

    public function testFileExistsAndDirectoryExists(): void
    {
        $filePath = $this->testDir . DIRECTORY_SEPARATOR . 'test.txt';
        $subDir = $this->testDir . DIRECTORY_SEPARATOR . 'sub';

        $this->assertFalse($this->command->callFileExists($filePath));
        $this->assertFalse($this->command->callDirectoryExists($subDir));

        $this->command->callWriteFile($filePath, 'content');
        $this->command->callEnsureDirectoryExists($subDir);

        $this->assertTrue($this->command->callFileExists($filePath));
        $this->assertTrue($this->command->callDirectoryExists($subDir));
    }

    public function testCopyAndPublishFile(): void
    {
        $sourcePath = $this->testDir . DIRECTORY_SEPARATOR . 'src.txt';
        $destPath = $this->testDir . DIRECTORY_SEPARATOR . 'dest.txt';
        $publishPath = $this->testDir . DIRECTORY_SEPARATOR . 'publish.txt';

        $this->command->callWriteFile($sourcePath, 'copy content');

        // Test copy
        $this->assertTrue($this->command->callCopy($sourcePath, $destPath));
        $this->assertSame('copy content', $this->command->callReadFile($destPath));

        // Test publish alias
        $this->assertTrue($this->command->callPublish($sourcePath, $publishPath));
        $this->assertSame('copy content', $this->command->callReadFile($publishPath));
    }

    public function testCopyOverwriteBehavior(): void
    {
        $sourcePath = $this->testDir . DIRECTORY_SEPARATOR . 'src.txt';
        $destPath = $this->testDir . DIRECTORY_SEPARATOR . 'dest.txt';

        $this->command->callWriteFile($sourcePath, 'new');
        $this->command->callWriteFile($destPath, 'old');

        // Without overwrite
        $this->assertFalse($this->command->callCopy($sourcePath, $destPath, false));
        $this->assertSame('old', $this->command->callReadFile($destPath));

        // With overwrite
        $this->assertTrue($this->command->callCopy($sourcePath, $destPath, true));
        $this->assertSame('new', $this->command->callReadFile($destPath));
    }

    public function testCopyDirectory(): void
    {
        $srcDir = $this->testDir . DIRECTORY_SEPARATOR . 'src_dir';
        $dstDir = $this->testDir . DIRECTORY_SEPARATOR . 'dst_dir';

        $this->command->callWriteFile($srcDir . '/file1.txt', 'one');
        $this->command->callWriteFile($srcDir . '/nested/file2.txt', 'two');

        $this->assertTrue($this->command->callCopy($srcDir, $dstDir));

        $this->assertTrue($this->command->callFileExists($dstDir . '/file1.txt'));
        $this->assertTrue($this->command->callFileExists($dstDir . '/nested/file2.txt'));
        $this->assertSame('one', $this->command->callReadFile($dstDir . '/file1.txt'));
        $this->assertSame('two', $this->command->callReadFile($dstDir . '/nested/file2.txt'));
    }

    public function testDeleteFile(): void
    {
        $filePath = $this->testDir . DIRECTORY_SEPARATOR . 'delete.txt';
        $this->command->callWriteFile($filePath, 'delete me');

        $this->assertTrue($this->command->callFileExists($filePath));
        $this->assertTrue($this->command->callDeleteFile($filePath));
        $this->assertFalse($this->command->callFileExists($filePath));
    }

    public function testDeleteDirectory(): void
    {
        $dirPath = $this->testDir . DIRECTORY_SEPARATOR . 'delete_dir';
        $this->command->callWriteFile($dirPath . '/sub/file.txt', 'content');

        $this->assertTrue($this->command->callDirectoryExists($dirPath));
        $this->assertTrue($this->command->callDeleteDirectory($dirPath));
        $this->assertFalse($this->command->callDirectoryExists($dirPath));
    }
}

/**
 * Concrete command stub that exposes protected file helper methods for testing.
 *
 * @internal
 */
class FileTestCommand extends Command
{
    protected function handle(): int
    {
        return self::SUCCESS;
    }

    public function callCopy(string $source, string $destination, bool $overwrite = true): bool
    {
        return $this->copy($source, $destination, $overwrite);
    }

    public function callPublish(string $source, string $destination, bool $overwrite = true): bool
    {
        return $this->publish($source, $destination, $overwrite);
    }

    public function callCopyDirectory(string $source, string $destination, bool $overwrite = true): bool
    {
        return $this->copyDirectory($source, $destination, $overwrite);
    }

    public function callDeleteFile(string $path): bool
    {
        return $this->deleteFile($path);
    }

    public function callDeleteDirectory(string $path): bool
    {
        return $this->deleteDirectory($path);
    }

    public function callFileExists(string $path): bool
    {
        return $this->fileExists($path);
    }

    public function callDirectoryExists(string $path): bool
    {
        return $this->directoryExists($path);
    }

    public function callEnsureDirectoryExists(string $path, int $mode = 0755, bool $recursive = true): bool
    {
        return $this->ensureDirectoryExists($path, $mode, $recursive);
    }

    public function callWriteFile(string $path, string $contents): bool
    {
        return $this->writeFile($path, $contents);
    }

    public function callReadFile(string $path): ?string
    {
        return $this->readFile($path);
    }
}
