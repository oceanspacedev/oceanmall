<?php

declare(strict_types=1);

namespace App\Support;

final class ComposerAutoloadPreload
{
    public static function prioritizeAppHelpers(): void
    {
        self::prioritizeAutoloadFiles();
        self::prioritizeAutoloadStatic();
    }

    private static function prioritizeAutoloadFiles(): void
    {
        $autoloadFiles = dirname(__DIR__, 2) . '/vendor/composer/autoload_files.php';
        if (! file_exists($autoloadFiles)) {
            return;
        }

        $content = file_get_contents($autoloadFiles);
        $needle = "/app/helpers.php',";

        $lines = explode("\n", $content);
        $helperLineIndex = null;
        $helperLineContent = null;

        foreach ($lines as $index => $line) {
            if (str_contains($line, $needle)) {
                $helperLineIndex = $index;
                $helperLineContent = trim($line);
                break;
            }
        }

        if ($helperLineIndex !== null && $helperLineContent !== null) {
            unset($lines[$helperLineIndex]);
            $reindexed = array_values($lines);

            $returnArrayIndex = null;
            foreach ($reindexed as $index => $line) {
                if (str_contains($line, 'return array(')) {
                    $returnArrayIndex = $index;
                    break;
                }
            }

            if ($returnArrayIndex !== null) {
                array_splice($reindexed, $returnArrayIndex + 1, 0, ["    " . $helperLineContent]);
                file_put_contents($autoloadFiles, implode("\n", $reindexed));
            }
        }
    }

    private static function prioritizeAutoloadStatic(): void
    {
        $autoloadStatic = dirname(__DIR__, 2) . '/vendor/composer/autoload_static.php';
        if (! file_exists($autoloadStatic)) {
            return;
        }

        $content = file_get_contents($autoloadStatic);
        $needle = "/app/helpers.php',";

        $lines = explode("\n", $content);
        $helperLineIndex = null;
        $helperLineContent = null;

        foreach ($lines as $index => $line) {
            if (str_contains($line, $needle)) {
                $helperLineIndex = $index;
                $helperLineContent = trim($line);
                break;
            }
        }

        if ($helperLineIndex !== null && $helperLineContent !== null) {
            unset($lines[$helperLineIndex]);
            $reindexed = array_values($lines);

            $filesArrayIndex = null;
            foreach ($reindexed as $index => $line) {
                if (str_contains($line, 'public static $files = array (')) {
                    $filesArrayIndex = $index;
                    break;
                }
            }

            if ($filesArrayIndex !== null) {
                array_splice($reindexed, $filesArrayIndex + 1, 0, ["        " . $helperLineContent]);
                file_put_contents($autoloadStatic, implode("\n", $reindexed));
            }
        }
    }
}
