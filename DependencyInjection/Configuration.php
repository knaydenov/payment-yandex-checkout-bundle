<?php
namespace Kna\PaymentYandexCheckoutBundle\DependencyInjection;


use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{

    /**
     * {@inheritdoc}
     */
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $root = $treeBuilder->root('kna_payment_yandex_checkout');

        $root
            ->children()
                ->arrayNode('jms_payment')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enable_event_listener')
                            ->defaultFalse()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;
        return $treeBuilder;
    }
}