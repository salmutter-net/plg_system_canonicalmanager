<?php

use Joomla\CMS\Application\SiteApplication;
use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use SalmutterNet\Plugin\System\Canonicalmanager\Extension\Canonicalmanager;

\defined('_JEXEC') or die;

return new class implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(PluginInterface::class, static function (Container $container): Canonicalmanager {
            $plugin = new Canonicalmanager(
                (array) PluginHelper::getPlugin('system', 'canonicalmanager'),
                $container->get(DatabaseInterface::class),
                static fn (): SiteApplication => Factory::getContainer()->get(SiteApplication::class)
            );
            $plugin->setApplication(Factory::getApplication());
            return $plugin;
        });
    }
};
