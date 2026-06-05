<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\FileWriter;

/**
 * Writes generated PHP files to disk.
 */
class FileWriter
{
    /**
     * Write a map of relative-path → content under $outputDir.
     *
     * @param array<string, string> $files Map of relative file path → file content
     */
    public function writeAll(string $outputDir, array $files): void
    {
        foreach ($files as $relativePath => $content) {
            $absolutePath = rtrim($outputDir, '/\\') . DIRECTORY_SEPARATOR . ltrim($relativePath, '/\\');
            $this->write($absolutePath, $content);
        }
    }

    public function write(string $absolutePath, string $content): void
    {
        $dir = dirname($absolutePath);

        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Unable to create directory: %s', $dir));
        }

        if (file_put_contents($absolutePath, $content) === false) {
            throw new \RuntimeException(sprintf('Unable to write file: %s', $absolutePath));
        }
    }

    /**
     * Remove all generated files under a directory.
     * Only removes files; leaves the directory structure intact.
     */
    public function clean(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                unlink($file->getRealPath());
            }
        }
    }
}
