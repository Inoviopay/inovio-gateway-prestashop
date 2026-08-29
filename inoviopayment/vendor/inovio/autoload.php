<?php
/**
 * Classmap autoloader for the vendored SDK (design doc D7).
 *
 * The SDK is vendored rather than pulled from Packagist because PrestaShop
 * Addons requires a self-contained single-module ZIP. The SDK has zero
 * Composer dependencies, so a small classmap loader is all it needs.
 */

spl_autoload_register(static function (string $class): void {
    if (strpos($class, 'Inovio\\Gateway\\') !== 0) {
        return;
    }

    static $map = null;
    if ($map === null) {
        $map = [];
        $base = __DIR__ . '/gateway-sdk/src';
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                // Classes are not 1:1 with filenames in this SDK, so index by content.
                $src = (string) file_get_contents($file->getPathname());
                if (preg_match('/^namespace\s+([^;]+);/m', $src, $ns)) {
                    preg_match_all('/^(?:final\s+|abstract\s+)?(?:class|interface|enum|trait)\s+(\w+)/m', $src, $names);
                    foreach ($names[1] as $name) {
                        $map[trim($ns[1]) . '\\' . $name] = $file->getPathname();
                    }
                }
            }
        }
    }

    if (isset($map[$class])) {
        require_once $map[$class];
    }
});
