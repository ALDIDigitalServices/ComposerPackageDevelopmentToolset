<?php

declare(strict_types=1);

namespace ALDIDigitalServices\ComposerPackageDevelopmentToolset;

use Exception;

class Filesystem
{
    public function getContents(string $path): string
    {
        $contents = file_get_contents($path);

        return $contents === false
            ? throw new Exception("Could not read '$path'")
            : $contents;
    }

    public function setContents(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents) === false) {
            throw new Exception("Could not write '$contents'");
        }
    }
}
