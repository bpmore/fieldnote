<?php

use Valet\Drivers\ValetDriver;

/**
 * Herd/Valet driver: serve public/ as the web root so data/, src/, and
 * internal/ are never reachable over HTTP.
 */
class LocalValetDriver extends ValetDriver
{
    public function serves(string $sitePath, string $siteName, string $uri): bool
    {
        return is_file($sitePath . '/public/index.php')
            && is_file($sitePath . '/src/routes.php');
    }

    public function isStaticFile(string $sitePath, string $siteName, string $uri)/*: string|false */
    {
        $staticFilePath = $sitePath . '/public' . $uri;
        if ($this->isActualFile($staticFilePath)) {
            return $staticFilePath;
        }

        return false;
    }

    public function frontControllerPath(string $sitePath, string $siteName, string $uri): ?string
    {
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['SCRIPT_FILENAME'] = $sitePath . '/public/index.php';
        $_SERVER['DOCUMENT_ROOT'] = $sitePath . '/public';

        return $sitePath . '/public/index.php';
    }
}
