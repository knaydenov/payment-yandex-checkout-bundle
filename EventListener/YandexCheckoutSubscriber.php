<?php
namespace Kna\PaymentYandexCheckoutBundle\EventListener;


use Doctrine\ORM\EntityManagerInterface;
use JMS\Payment\CoreBundle\Entity\FinancialTransaction;
use JMS\Payment\CoreBundle\PluginController\PluginControllerInterface;
use JMS\Payment\CoreBundle\PluginController\Result;
use Kna\YandexCheckoutBundle\Event\NotificationEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use YandexCheckout\Client;
use YandexCheckout\Model\Notification\NotificationCanceled;
use YandexCheckout\Model\Notification\NotificationRefundSucceeded;
use YandexCheckout\Model\Notification\NotificationSucceeded;
use YandexCheckout\Model\Notification\NotificationWaitingForCapture;

class YandexCheckoutSubscriber implements EventSubscriberInterface
{
    /**
     * @var Client
     */
    protected $client;

    /**
     * @var PluginControllerInterface
     */
    protected $pluginController;

    /**
     * @var string
     */
    protected $paymentIdKey;

    /**
     * @var EntityManagerInterface
     */
    private $entityManager;

    public function __construct(
        Client $client,
        PluginControllerInterface $pluginController,
        EntityManagerInterface $entityManager,
        string $paymentIdKey
    )
    {
        $this->client = $client;
        $this->pluginController = $pluginController;
        $this->entityManager = $entityManager;
        $this->paymentIdKey = $paymentIdKey;
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            NotificationEvent::class => 'onNotificationReceived'
        ];
    }

    public function onNotificationReceived(NotificationEvent $event)
    {
        $notification = $event->getNotification();

        switch (true) {
            case $notification instanceof NotificationWaitingForCapture:
                $this->onNotificationWaitingForCapture($notification);
                break;
            case $notification instanceof NotificationSucceeded:
                $this->onNotificationSucceeded($notification);
                break;
            case $notification instanceof NotificationCanceled:
                $this->onNotificationCanceled($notification);
                break;
            case $notification instanceof NotificationRefundSucceeded:
                $this->onNotificationRefundSucceeded($notification);
                break;
            default:
                throw new  \RuntimeException('Unknown notification event');
        }

        $event->setAccepted(true);
    }

    protected function onNotificationWaitingForCapture(NotificationWaitingForCapture $notification)
    {
        $yandexPayment = $notification->getObject();

        /** @var FinancialTransaction $transaction */
        $transaction = $this->entityManager->getRepository(FinancialTransaction::class)->findOneBy(['referenceNumber' => $yandexPayment->getId()]);

        if (!$transaction) {
            throw new \RuntimeException('Transaction not found');
        }

        $payment = $transaction->getPayment();

        $result = $this->pluginController->approveAndDeposit($payment->getId(), $yandexPayment->getAmount()->getValue());

        if ($result->getStatus() === Result::STATUS_FAILED) {
            throw $result->getPluginException();
        }
    }

    protected function onNotificationSucceeded(NotificationSucceeded $notification)
    {
        $yandexPayment = $notification->getObject();

        /** @var FinancialTransaction $transaction */
        $transaction = $this->entityManager->getRepository(FinancialTransaction::class)->findOneBy(['referenceNumber' => $yandexPayment->getId()]);

        if (!$transaction) {
            throw new \RuntimeException('Transaction not found');
        }

        $payment = $transaction->getPayment();

        $result = $this->pluginController->approveAndDeposit($payment->getId(), $yandexPayment->getAmount()->getValue());

        if ($result->getStatus() === Result::STATUS_FAILED) {
            throw $result->getPluginException();
        }
    }

    protected function onNotificationCanceled(NotificationCanceled $notification)
    {
        $yandexPayment = $notification->getObject();

        /** @var FinancialTransaction $transaction */
        $transaction = $this->entityManager->getRepository(FinancialTransaction::class)->findOneBy(['referenceNumber' => $yandexPayment->getId()]);

        if (!$transaction) {
            throw new \RuntimeException('Transaction not found');
        }

        $payment = $transaction->getPayment();

        $result = $this->pluginController->approveAndDeposit($payment->getId(), $yandexPayment->getAmount()->getValue());

        if ($result->getStatus() === Result::STATUS_FAILED) {
            throw $result->getPluginException();
        }
    }

    protected function onNotificationRefundSucceeded(NotificationRefundSucceeded $notification)
    {
        $yandexRefund = $notification->getObject();

        /** @var FinancialTransaction $transaction */
        $transaction = $this->entityManager->getRepository(FinancialTransaction::class)->findOneBy(['referenceNumber' => $yandexRefund->getId()]);

        if (!$transaction) {
            throw new \RuntimeException('Transaction not found');
        }

        $credit = $transaction->getCredit();

        $result = $this->pluginController->credit($credit->getId(), $yandexRefund->getAmount()->getValue());

        if ($result->getStatus() === Result::STATUS_FAILED) {
            throw $result->getPluginException();
        }
    }

}