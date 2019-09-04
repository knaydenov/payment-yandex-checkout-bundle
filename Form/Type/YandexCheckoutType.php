<?php


namespace Kna\PaymentYandexCheckoutBundle\Form\Type;


use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\ChoiceList\Loader\CallbackChoiceLoader;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use YandexCheckout\Model\PaymentMethodType;

class YandexCheckoutType extends AbstractType
{

    /**
     * @param FormBuilderInterface $builder
     * @param array $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('payment_method', YandexCheckoutPaymentMethodType::class, [
                'payment_methods' => $options['payment_methods']
            ])
        ;
    }

    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'payment_methods' => [PaymentMethodType::BANK_CARD],
            'translation_domain' => 'KnaYandexCheckoutBundle'
        ]);
        $resolver->setAllowedTypes('payment_methods', 'string[]');
    }

    public function getName()
    {
        return 'yandex_checkout';
    }
}