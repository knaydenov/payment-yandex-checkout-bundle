<?php


namespace Kna\PaymentYandexCheckoutBundle\Plugin;


use JMS\Payment\CoreBundle\Model\CreditInterface;
use JMS\Payment\CoreBundle\Model\ExtendedDataInterface;
use JMS\Payment\CoreBundle\Model\FinancialTransactionInterface;
use JMS\Payment\CoreBundle\Model\PaymentInstructionInterface;
use JMS\Payment\CoreBundle\Model\PaymentInterface;
use JMS\Payment\CoreBundle\Plugin\AbstractPlugin;
use JMS\Payment\CoreBundle\Plugin\PluginInterface;
use JMS\Payment\CoreBundle\Plugin\QueryablePluginInterface;
use Kna\PaymentYandexCheckoutBundle\Event\CaptureRequestedEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use YandexCheckout\Client;
use YandexCheckout\Model\ConfirmationType;
use YandexCheckout\Model\MonetaryAmount;
use YandexCheckout\Model\PaymentInterface as YandexPaymentInterface;
use YandexCheckout\Model\RefundInterface as YandexRefundInterface;
use YandexCheckout\Model\PaymentStatus;
use YandexCheckout\Model\RefundStatus;
use YandexCheckout\Request\Payments\CreatePaymentRequest;
use YandexCheckout\Request\Payments\CreatePaymentRequestInterface;
use YandexCheckout\Request\Payments\CreatePaymentResponse;
use YandexCheckout\Request\Payments\Payment\CancelResponse;
use YandexCheckout\Request\Payments\Payment\CreateCaptureResponse;
use YandexCheckout\Request\Payments\PaymentResponse;
use YandexCheckout\Request\Refunds\CreateRefundRequest;
use YandexCheckout\Request\Refunds\CreateRefundRequestInterface;
use YandexCheckout\Request\Refunds\CreateRefundResponse;
use YandexCheckout\Request\Refunds\RefundResponse;
use YandexCheckout\Common\Exceptions as YandexCheckoutExceptions;
use JMS\Payment\CoreBundle\Plugin\Exception as JMSPaymentException;
use Kna\PaymentYandexCheckoutBundle\Plugin\Exception as KnaYandexCheckoutBundlePluginException;


class YandexCheckoutPlugin extends AbstractPlugin implements QueryablePluginInterface
{

    protected $paymentIdKey;

    /**
     * @var Client
     */
    protected $client;

    /**
     * @var EventDispatcherInterface
     */
    protected $eventDispatcher;

    public function __construct(
        Client $client,
        EventDispatcherInterface $eventDispatcher,
        string $paymentIdKey,
        $isDebug = false
    )
    {
        $this->client = $client;
        $this->eventDispatcher = $eventDispatcher;
        $this->paymentIdKey = $paymentIdKey;
        parent::__construct($isDebug);
    }

    /**
     * {@inheritDoc}
     */
    public function processes($paymentSystemName)
    {
        return 'yandex_checkout' === $paymentSystemName;
    }

    /**
     * {@inheritDoc}
     */
    public function getAvailableBalance(PaymentInstructionInterface $paymentInstruction)
    {
        return null;
    }

    /**
     * @param CreditInterface $credit
     * @throws YandexCheckoutExceptions\ApiException
     * @throws YandexCheckoutExceptions\BadApiRequestException
     * @throws YandexCheckoutExceptions\ForbiddenException
     * @throws YandexCheckoutExceptions\InternalServerError
     * @throws YandexCheckoutExceptions\NotFoundException
     * @throws YandexCheckoutExceptions\ResponseProcessingException
     * @throws YandexCheckoutExceptions\TooManyRequestsException
     * @throws YandexCheckoutExceptions\UnauthorizedException
     */
    public function updateCredit(CreditInterface $credit)
    {
        if ($credit->hasPendingTransaction()) {
            $yandexRefund = $this->restoreYandexRefund($credit->getPendingTransaction());

            if ($yandexRefund) {
                $yandexRefund = $this->client->getRefundInfo($yandexRefund->getId());
                $this->dumpYandexRefund($credit->getPendingTransaction(), $yandexRefund);
            }
        }
    }

    /**
     * @param PaymentInterface $payment
     * @throws YandexCheckoutExceptions\ApiException
     * @throws YandexCheckoutExceptions\BadApiRequestException
     * @throws YandexCheckoutExceptions\ForbiddenException
     * @throws YandexCheckoutExceptions\InternalServerError
     * @throws YandexCheckoutExceptions\NotFoundException
     * @throws YandexCheckoutExceptions\ResponseProcessingException
     * @throws YandexCheckoutExceptions\TooManyRequestsException
     * @throws YandexCheckoutExceptions\UnauthorizedException
     */
    public function updatePayment(PaymentInterface $payment)
    {
        if ($payment->hasPendingTransaction()) {
            $yandexPayment = $this->restoreYandexPayment($payment->getPendingTransaction()->getExtendedData(), $payment->getId());

            if ($yandexPayment) {
                $yandexPayment = $this->client->getPaymentInfo($yandexPayment->getId());
                $this->dumpYandexPayment($payment->getPendingTransaction()->getExtendedData(), $payment->getId(), $yandexPayment);
            }
        }
    }

    /**
     * @param FinancialTransactionInterface $transaction
     * @param bool $retry
     * @throws Exception\PaymentCreateRequestFailedException
     * @throws JMSPaymentException\ActionRequiredException
     * @throws JMSPaymentException\FinancialException
     * @throws JMSPaymentException\FunctionNotSupportedException
     */
    public function approveAndDeposit(FinancialTransactionInterface $transaction, $retry)
    {
        $data = $transaction->getExtendedData();

        if ($transaction->getState() === FinancialTransactionInterface::STATE_NEW) {
            $capture = $data->has('capture') ? $data->get('capture') : false;

            $createPaymentResponse = $this->createPayment($transaction, $capture);
            $this->dumpYandexPayment($data, $transaction->getPayment()->getId(), $createPaymentResponse);

            if ($createPaymentResponse->getConfirmation()->getType() === ConfirmationType::REDIRECT) {
                if (!$createPaymentResponse->getConfirmation()->offsetExists('confirmationUrl')) {
                    throw $this->createFinancialException('Confirmation url not found');
                }

                $transaction->getExtendedData()->set('confirmation_url', $createPaymentResponse->getConfirmation()->offsetGet('confirmationUrl'));
                $transaction->getExtendedData()->set('confirmation_type', $createPaymentResponse->getConfirmation()->getType());

            }
        }

        $yandexPayment = $this->restoreYandexPayment($data, $transaction->getPayment()->getId());

        if (!$yandexPayment) {
            throw $this->createFinancialException('Yandex payment not found');
        }

        $transaction->setReferenceNumber($yandexPayment->getId());

        switch ($yandexPayment->getStatus()) {
            case PaymentStatus::PENDING:
                if ($transaction->getExtendedData()->get('confirmation_type') === ConfirmationType::REDIRECT) {
                    throw $this->createActionRequiredException($transaction, $transaction->getExtendedData()->get('confirmation_url'));
                }
                break;
            case PaymentStatus::WAITING_FOR_CAPTURE:
                $waitingForCaptureEvent = new CaptureRequestedEvent($yandexPayment);
                $this->eventDispatcher->dispatch($waitingForCaptureEvent);

                if ($waitingForCaptureEvent->shouldCapture()) {
                    try {
                        $capturePaymentResponse = $this->capturePayment($transaction);
                    } catch (KnaYandexCheckoutBundlePluginException\PaymentCaptureRequestFailedException $exception) {
                        throw $this->createFinancialException('Payment capturing failed');
                    }

                    $this->dumpYandexPayment($transaction->getExtendedData(), $transaction->getPayment()->getId(), $capturePaymentResponse);
                } else {
                    try {
                        $cancelPaymentResponse = $this->cancelPayment($transaction);
                    } catch (KnaYandexCheckoutBundlePluginException\PaymentCancelRequestFailedException $exception) {
                        throw $this->createFinancialException($exception->getMessage());
                    }

                    $this->dumpYandexPayment($transaction->getExtendedData(), $transaction->getPayment()->getId(), $cancelPaymentResponse);
                }
                $this->approveAndDeposit($transaction, $retry);
                break;
            case PaymentStatus::SUCCEEDED:
                $transaction->setProcessedAmount($yandexPayment->getAmount()->getValue());
                $transaction->setResponseCode(PluginInterface::RESPONSE_CODE_SUCCESS);
                $transaction->setReasonCode(PluginInterface::RESPONSE_CODE_SUCCESS);
                break;
            default:
            case PaymentStatus::CANCELED:
                throw $this->createFinancialException('Failed to approve');
                break;

        }
    }

    /**
     * @param FinancialTransactionInterface $transaction
     * @param bool $retry
     * @throws Exception\RefundCreateRequestFailedException
     * @throws JMSPaymentException\BlockedException
     * @throws JMSPaymentException\FinancialException
     */
    public function credit(FinancialTransactionInterface $transaction, $retry)
    {
        $yandexRefund = $this->restoreYandexRefund($transaction);

        if (!$yandexRefund) {
            $yandexRefund = $this->createRefund($transaction);
            $this->dumpYandexRefund($transaction, $yandexRefund);
        }

        switch ($yandexRefund->getStatus()) {
            case RefundStatus::PENDING:
                throw $this->createBlockedException('Refund is pending');
                break;
            case RefundStatus::SUCCEEDED:
                $transaction->setProcessedAmount($yandexRefund->getAmount()->getValue());
                $transaction->setReferenceNumber($yandexRefund->getId());
                $transaction->setResponseCode(PluginInterface::RESPONSE_CODE_SUCCESS);
                $transaction->setReasonCode(PluginInterface::RESPONSE_CODE_SUCCESS);
                break;
            case RefundStatus::CANCELED:
                throw $this->createFinancialException('Failed to refund');
                break;
        }
    }

    /**
     * @param ExtendedDataInterface $data
     * @param $paymentId
     * @return YandexPaymentInterface|null
     */
    protected function restoreYandexPayment(ExtendedDataInterface $data, $paymentId): ?YandexPaymentInterface
    {
        $payments = $data->has('payments') ? $data->get('payments'): [];

        if (isset($payments[$paymentId])) {
            return new PaymentResponse($payments[$paymentId]);
        }

        return null;
    }

    /**
     * @param ExtendedDataInterface $data
     * @param $paymentId
     * @param YandexPaymentInterface $yandexPayment
     */
    protected function dumpYandexPayment(ExtendedDataInterface $data, $paymentId, YandexPaymentInterface $yandexPayment)
    {
        /** @var PaymentResponse  $yandexPayment */
        $payments = $data->has('payments') ? $data->get('payments'): [];
        $payments[$paymentId] = $yandexPayment->jsonSerialize();
        $data->set('payments', $payments);
    }

    /**
     * @param FinancialTransactionInterface $transaction
     * @return YandexRefundInterface|null
     */
    protected function restoreYandexRefund(FinancialTransactionInterface $transaction): ?YandexRefundInterface
    {
        $data = $transaction->getExtendedData();
        $refunds = $data->has('refunds') ? $data->get('refunds'): [];

        if (isset($refunds[$transaction->getCredit()->getId()])) {
            return new RefundResponse($refunds[$transaction->getPayment()->getId()]);
        }

        return null;
    }

    /**
     * @param FinancialTransactionInterface $transaction
     * @param YandexRefundInterface $yandexRefund
     */
    protected function dumpYandexRefund(FinancialTransactionInterface $transaction, YandexRefundInterface $yandexRefund)
    {
        /** @var RefundResponse $yandexRefund */
        $data = $transaction->getExtendedData();
        $refunds = $data->has('refunds') ? $data->get('refunds'): [];
        $refunds[$transaction->getCredit()->getId()] = $yandexRefund->jsonSerialize();
        $data->set('refunds', $refunds);

    }

    /**
     * @param FinancialTransactionInterface $transaction
     * @return CreateCaptureResponse
     * @throws Exception\PaymentCaptureRequestFailedException
     */
    protected function capturePayment(FinancialTransactionInterface $transaction) {
        $yandexPayment = $this->restoreYandexPayment($transaction->getExtendedData(), $transaction->getPayment()->getId());

        try {
            $createCaptureResponse = $this->client->capturePayment(['amount' => $transaction->getRequestedAmount()], $yandexPayment->getId(), uniqid('', true));
        } catch (\Exception $exception) {
            throw new KnaYandexCheckoutBundlePluginException\PaymentCaptureRequestFailedException($exception->getMessage());
        }

        return $createCaptureResponse;
    }

    /**
     * @param FinancialTransactionInterface $transaction
     * @param bool $capture
     * @return CreatePaymentResponse
     * @throws Exception\PaymentCreateRequestFailedException
     */
    protected function createPayment(FinancialTransactionInterface $transaction, bool $capture = false)
    {
        $createPaymentRequest = $this->buildCreatePaymentRequest($transaction, $capture);
        try {
            $createPaymentResponse = $this->client->createPayment($createPaymentRequest, uniqid('', true));
        } catch (\Exception $exception) {
            throw new KnaYandexCheckoutBundlePluginException\PaymentCreateRequestFailedException($exception->getMessage());
        }
        return $createPaymentResponse;
    }

    /**
     * @param FinancialTransactionInterface $transaction
     * @return CancelResponse
     * @throws Exception\PaymentCancelRequestFailedException
     */
    protected function cancelPayment(FinancialTransactionInterface $transaction)
    {
        $yandexPayment = $this->restoreYandexPayment($transaction->getExtendedData(), $transaction->getPayment()->getId());

        try {
            $cancelPaymentResponse = $this->client->cancelPayment($yandexPayment->getId(), uniqid('', true));
        } catch (\Exception $exception) {
            throw new KnaYandexCheckoutBundlePluginException\PaymentCancelRequestFailedException($exception->getMessage());
        }
        return $cancelPaymentResponse;
    }

    /**
     * @param FinancialTransactionInterface $transaction
     * @return CreateRefundResponse
     * @throws Exception\RefundCreateRequestFailedException
     */
    protected function createRefund(FinancialTransactionInterface $transaction)
    {
        $createRefundRequest = $this->buildCreateRefundRequest($transaction);
        try {
            $createRefundResponse = $this->client->createRefund($createRefundRequest, uniqid('', true));
        } catch (\Exception $exception) {
            throw new KnaYandexCheckoutBundlePluginException\RefundCreateRequestFailedException($exception->getMessage());
        }
        return $createRefundResponse;
    }

    /**
     * @param FinancialTransactionInterface $transaction
     * @param bool $capture
     * @return CreatePaymentRequest|CreatePaymentRequestInterface
     */
    protected function buildCreatePaymentRequest(FinancialTransactionInterface $transaction, bool $capture = false): CreatePaymentRequest
    {
        $instruction = $transaction->getPayment()->getPaymentInstruction();
        $payment = $transaction->getPayment();
        $data = $instruction->getExtendedData();

        $builder = CreatePaymentRequest::builder();
        $builder
            ->setCapture($capture)
            ->setMetadata([$this->paymentIdKey => $payment->getId()])
            ->setPaymentMethodData(['type' => $data->get('payment_method')])
            ->setAmount(new MonetaryAmount($payment->getTargetAmount(), $instruction->getCurrency()))
        ;

        $confirmationType = $data->get('confirmation_type');
        $confirmation = ['type' => $confirmationType];

        switch ($confirmationType) {
            case ConfirmationType::REDIRECT:
                $confirmation['return_url'] = $data->get('return_url');

                if ($data->has('enforce')) {
                    $confirmation['enforce'] = $data->get('enforce');
                }

                if ($data->has('locale')) {
                    $confirmation['locale'] = $data->get('locale');
                }
                break;
            case ConfirmationType::EXTERNAL:
            case ConfirmationType::QR:
                if ($data->has('locale')) {
                    $confirmation['locale'] = $data->get('locale');
                }
                break;
        }
        $builder->setConfirmation($confirmation);

        return $builder->build();
    }

    /**
     * @param FinancialTransactionInterface $transaction
     * @return CreateRefundRequest|CreateRefundRequestInterface
     */
    protected function buildCreateRefundRequest(FinancialTransactionInterface $transaction): CreateRefundRequest
    {
        $credit = $transaction->getCredit();
        $instruction = $credit->getPayment()->getPaymentInstruction();

        $yandexPayment = $this->restoreYandexPayment($transaction->getExtendedData(), $credit->getPayment()->getId());

        if (!$yandexPayment) {
            throw new \RuntimeException('Payment not found');
        }

        $builder = CreateRefundRequest::builder();
        $builder
            ->setPaymentId($yandexPayment->getId())
            ->setAmount(new MonetaryAmount($credit->getTargetAmount(), $instruction->getCurrency()))
        ;

        return $builder->build();
    }

    /**
     * @param FinancialTransactionInterface $transaction
     * @param string $confirmationUrl
     * @return JMSPaymentException\ActionRequiredException
     */
    protected function createActionRequiredException(FinancialTransactionInterface $transaction, string $confirmationUrl)
    {
        $exception = new JMSPaymentException\ActionRequiredException('User must authorize the transaction');
        $exception->setFinancialTransaction($transaction);
        $exception->setAction(new JMSPaymentException\Action\VisitUrl($confirmationUrl));
        return $exception;
    }

    /**
     * @param string $message
     * @return JMSPaymentException\FinancialException
     */
    protected function createFinancialException(string $message)
    {
        $exception = new JMSPaymentException\FinancialException($message);
        return $exception;
    }

    /**
     * @param string $message
     * @return JMSPaymentException\BlockedException
     */
    protected function createBlockedException(string $message)
    {
        $exception = new JMSPaymentException\BlockedException($message);
        return $exception;
    }
}