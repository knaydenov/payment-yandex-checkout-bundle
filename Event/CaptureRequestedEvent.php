<?php


namespace Kna\PaymentYandexCheckoutBundle\Event;


use Symfony\Contracts\EventDispatcher\Event;
use YandexCheckout\Model\PaymentInterface;

class CaptureRequestedEvent extends Event
{

    /**
     * @var boolean
     */
    protected $capture;

    /**
     * @var PaymentInterface
     */
    protected $payment;

    public function __construct(PaymentInterface $payment, bool $capture = false)
    {
        $this->payment = $payment;
        $this->capture = $capture;
    }

    /**
     * @return bool
     */
    public function shouldCapture(): bool
    {
        return $this->capture;
    }

    /**
     * @param bool $capture
     */
    public function setCapture(bool $capture): void
    {
        $this->capture = $capture;
    }

    /**
     * @return PaymentInterface
     */
    public function getPayment(): PaymentInterface
    {
        return $this->payment;
    }

    /**
     * @param PaymentInterface $payment
     */
    public function setPayment(PaymentInterface $payment): void
    {
        $this->payment = $payment;
    }

}