<?php


namespace Kna\PaymentYandexCheckoutBundle\Form\Type;


use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\ChoiceList\Loader\CallbackChoiceLoader;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

class YandexCheckoutPaymentMethodType extends AbstractType
{
    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'choice_loader' => function (Options $options) {
                $payment_methods = $options['payment_methods'];
                return new CallbackChoiceLoader(function () use ($payment_methods) {
                    foreach ($payment_methods as $payment_method) {
                        yield 'payment_method.' . $payment_method => $payment_method;
                    }
                });
            },
            'payment_methods' => []
        ]);

        $resolver->setAllowedTypes('payment_methods', 'string[]');
    }

    /**
     * {@inheritdoc}
     */
    public function getParent()
    {
        return ChoiceType::class;
    }
}