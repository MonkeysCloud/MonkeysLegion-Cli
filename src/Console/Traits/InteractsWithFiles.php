<?php

declare(strict_types=1);

namespace MonkeysLegion\Cli\Console\Traits;

/**
 * Trait InteractsWithFiles
 *
 * Provides helpers for standard file and directory operations (copying, publishing, deleting, etc.).
 */
trait InteractsWithFiles
{
    /**
     * Copy a file or directory to a destination.
     *
     * @param string $source      Source file or directory path
     * @param string $destination Destination path
     * @param bool   $overwrite   Whether to overwrite existing files
     * @return bool True on success, false on failure
     */
    protected function copy(string $source, string $destination, bool $overwrite = true): bool
    {
        if (!file_exists($source)) {
            return false;
        }

        if (is_dir($source)) {
            return $this->copyDirectory($source, $destination, $overwrite);
        }

        if (!$overwrite && file_exists($destination)) {
            return false;
        }

        $parentDir = dirname($destination);
        if (!is_dir($parentDir)) {
            mkdir($parentDir, 0755, true);
        }

        return copy($source, $destination);
    }

    /**
     * Publish a file or directory (alias to copy).
     *
     * @param string $source      Source file or directory path
     * @param string $destination Destination path
     * @param bool   $overwrite   Whether to overwrite existing files
     * @return bool True on success, false on failure
     */
    protected function publish(string $source, string $destination, bool $overwrite = true): bool
    {
        return $this->copy($source, $destination, $overwrite);
    }

    /**
     * Copy a directory and its contents recursively.
     *
     * @param string $source      Source directory path
     * @param string $destination Destination directory path
     * @param bool   $overwrite   Whether to overwrite existing files
     * @return bool True on success, false on failure
     */
    protected function copyDirectory(string $source, string $destination, bool $overwrite = true): bool
    {
        if (!is_dir($source)) {
            return false;
        }

        if (!is_dir($destination)) {
            mkdir($destination, 0755, true);
        }

        $dir = opendir($source);
        if ($dir === false) {
            return false;
        }

        $success = true;
        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $srcPath = $source . DIRECTORY_SEPARATOR . $file;
            $dstPath = $destination . DIRECTORY_SEPARATOR . $file;

            if (is_dir($srcPath)) {
                $success = $success && $this->copyDirectory($srcPath, $dstPath, $overwrite);
            } else {
                if ($overwrite || !file_exists($dstPath)) {
                    $success = $success && copy($srcPath, $dstPath);
                }
            }
        }
        closedir($dir);

        return $success;
    }

    /**
     * Delete a file.
     *
     * @param string $path File path to delete
     * @return bool True on success, false on failure
     */
    protected function deleteFile(string $path): bool
    {
        if (!file_exists($path) || is_dir($path)) {
            return false;
        }

        return unlink($path);
    }

    /**
     * Delete a directory and all of its contents.
     *
     * @param string $path Directory path to delete
     * @return bool True on success, false on failure
     */
    protected function deleteDirectory(string $path): bool
    {
        if (!is_dir($path)) {
            return false;
        }

        $files = array_diff(scandir($path) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $currPath = $path . DIRECTORY_SEPARATOR . $file;
            if (is_dir($currPath)) {
                $this->deleteDirectory($currPath);
            } else {
                unlink($currPath);
            }
        }

        return rmdir($path);
    }

    /**
     * Check if a file exists.
     *
     * @param string $path
     * @return bool
     */
    protected function fileExists(string $path): bool
    {
        return file_exists($path) && !is_dir($path);
    }

    /**
     * Check if a directory exists.
     *
     * @param string $path
     * @return bool
     */
    protected function directoryExists(string $path): bool
    {
        return is_dir($path);
    }

    /**
     * Ensure that a directory exists, creating it if necessary.
     *
     * @param string $path
     * @param int    $mode
     * @param bool   $recursive
     * @return bool
     */
    protected function ensureDirectoryExists(string $path, int $mode = 0755, bool $recursive = true): bool
    {
        if (is_dir($path)) {
            return true;
        }

        return mkdir($path, $mode, $recursive);
    }

    /**
     * Write contents to a file.
     *
     * @param string $path
     * @param string $contents
     * @return bool
     */
    protected function writeFile(string $path, string $contents): bool
    {
        $parent = dirname($path);
        if (!is_dir($parent)) {
            mkdir($parent, 0755, true);
        }

        return file_put_contents($path, $contents) !== false;
    }

    /**
     * Read contents from a file.
     *
     * @param string $path
     * @return string|null The content, or null if the file does not exist
     */
    protected function readFile(string $path): ?string
    {
        if (!file_exists($path) || is_dir($path)) {
            return null;
        }

        $content = file_get_contents($path);

        return $content === false ? null : $content;
    }
}
