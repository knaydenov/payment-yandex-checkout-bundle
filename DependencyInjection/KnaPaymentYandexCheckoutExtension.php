<?php
namespace Kna\PaymentYandexCheckoutBundle\DependencyInjection;



use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

class KnaPaymentYandexCheckoutExtension extends Extension
{

    /**
     * {@inheritdoc}
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));

        $loader->load('services.yaml');

        if (!$config['jms_payment']['enable_event_listener']) {
            $container->removeDefinition('kna_yandex_checkout.jms_payment_subscriber');
        }

    }
}