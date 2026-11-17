<?php
/**
 * Service provider — registers the Infynipay plugin with Joomla's
 * DI container so that SubscriberInterface event listeners are properly
 * wired to the event dispatcher (required for com_ajax callbacks, etc.).
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use Joomla\Plugin\PhocacartPayment\Infynipay\Extension\Infynipay;

return new class implements ServiceProviderInterface
{
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            static function (Container $container): Infynipay {
                $dispatcher = $container->get(DispatcherInterface::class);
                $plugin     = new Infynipay(
                    $dispatcher,
                    (array) PluginHelper::getPlugin('pcp', 'infynipay')
                );
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            }
        );
    }
};
