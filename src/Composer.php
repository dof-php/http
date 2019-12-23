<?php

declare(strict_types=1);

namespace DOF\HTTP;

use Exception;
use DOF\Convention;
use DOF\Util\Exceptor;
use Composer\Script\Event;
use Composer\Installer\PackageEvent;

// https://getcomposer.org/doc/articles/scripts.md
final class Composer
{
    const VENDOR = 'dof-php/http';

    public static function postPackageUpdate(PackageEvent $event)
    {
        if (Composer::VENDOR !== $event->getOperation()->getPackage()->getName()) {
            return;
        }
        
        // TODO
    }

    public static function postPackageInstall(PackageEvent $event)
    {
        if (Composer::VENDOR !== $event->getOperation()->getPackage()->getName()) {
            return;
        }

        // TODO
    }

    public static function postUpdateCMD(Event $event)
    {
        Composer::postInstallCMD($event);
    }

    public static function postInstallCMD(Event $event)
    {
        $webEntry = \join(DIRECTORY_SEPARATOR, [\dirname($event->getComposer()->getConfig()->get('vendor-dir')), Convention::DIR_WEBSITE]);
        if ((! \is_dir($webEntry)) && (false === @mkdir($webEntry, 0755, true))) {
            throw new Exceptor('INSTALL_WEBSITE_ENTRY_FAILED', \compact('webEntry'));
        }
        $httpBooter = \join(DIRECTORY_SEPARATOR, [$webEntry, Convention::FILE_FPM_BOOTER]);
        if (\is_file($httpBooter)) {
            return;
        }

        $status = @copy(\join(DIRECTORY_SEPARATOR, [\dirname(\dirname(__FILE__)), 'tpl', 'legacy-booter']), $httpBooter);
        if (false === $status) {
            throw new Exceptor('INSTALL_LEGACY_HTTP_BOOTER_FAILED', 'httpBooter');
        }
    }
}
