<?php

// Pitchdeck vendor autoloader — standalone, no Composer\Autoload\ClassLoader dependency.
// Registers PSR-0 (smalot/pdfparser) and PSR-4 (symfony/polyfill-mbstring) via spl_autoload_register.

$vendorDir = __DIR__;

// PSR-0: smalot/pdfparser
// Class Smalot\PdfParser\Foo => vendor/smalot/pdfparser/src/Smalot/PdfParser/Foo.php
$psr0Base = $vendorDir . '/smalot/pdfparser/src';

spl_autoload_register(function ($class) use ($psr0Base) {
    if (strpos($class, 'Smalot\\') !== 0) {
        return;
    }
    $file = $psr0Base . DIRECTORY_SEPARATOR
          . str_replace(['\\', '_'], DIRECTORY_SEPARATOR, $class)
          . '.php';
    if (file_exists($file)) {
        require $file;
    }
}, true, false);

// PSR-4: symfony/polyfill-mbstring
// Class Symfony\Polyfill\Mbstring\Foo => vendor/symfony/polyfill-mbstring/Foo.php
$polyfillBase = $vendorDir . '/symfony/polyfill-mbstring';
$polyfillPrefix = 'Symfony\\Polyfill\\Mbstring\\';

spl_autoload_register(function ($class) use ($polyfillBase, $polyfillPrefix) {
    if (strpos($class, $polyfillPrefix) !== 0) {
        return;
    }
    $relative = substr($class, strlen($polyfillPrefix));
    $file = $polyfillBase . DIRECTORY_SEPARATOR
          . str_replace('\\', DIRECTORY_SEPARATOR, $relative)
          . '.php';
    if (file_exists($file)) {
        require $file;
    }
}, true, false);

// Bootstrap file for polyfill-mbstring (registers mb_* function shims if ext-mbstring missing).
$bootstrap = $polyfillBase . '/bootstrap.php';
if (file_exists($bootstrap)) {
    require_once $bootstrap;
}
