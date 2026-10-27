<?php

namespace App\Support\Composer;

use Composer\Script\Event;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\PackageManifest;

/**
 * Builds Laravel's package manifest without calling `artisan`
 * (Hostinger CLI often disables proc_open, which breaks Symfony Process).
 */
class PackageDiscover
{
    public static function discover(Event $event): void
    {
        $vendorDir = $event->getComposer()->getConfig()->get('vendor-dir');

        require_once $vendorDir.'/autoload.php';

        $basePath = getcwd() ?: dirname($vendorDir, 2);

        $manifest = new PackageManifest(
            new Filesystem,
            $basePath,
            $basePath.'/bootstrap/cache/packages.php'
        );

        $manifest->build();
    }
}
