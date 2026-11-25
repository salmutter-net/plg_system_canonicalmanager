<?php

use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\CMS\Version;

\defined('_JEXEC') or die;

return new class implements InstallerScriptInterface {
    public function preflight(string $type, InstallerAdapter $adapter): bool
    {
        if ($type !== 'uninstall' && (PHP_VERSION_ID < 80300 || !in_array(Version::MAJOR_VERSION, [5, 6], true))) {
            throw new \RuntimeException('Canonical Manager requires Joomla 5 or 6 and PHP 8.3 or newer.');
        }
        return true;
    }

    public function install(InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function update(InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function uninstall(InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function postflight(string $type, InstallerAdapter $adapter): bool
    {
        return true;
    }
};
