<?php
namespace Sofort\Procedures;

use Plenty\Modules\EventProcedures\Events\EventProceduresTriggered;
use Plenty\Modules\Order\Models\Order;
use Plenty\Modules\Order\Models\OrderAmount;
use Plenty\Modules\Order\Models\OrderReference;
use Plenty\Modules\Payment\Contracts\PaymentRepositoryContract;
use Plenty\Modules\Payment\Models\Payment;
use Plenty\Modules\Payment\Models\PaymentProperty;
use Plenty\Plugin\ConfigRepository;
use Plenty\Plugin\Log\Loggable;
use Sofort\Helper\PaymentHelper;
use Sofort\Services\PaymentService;

/**
 * Class RefundEventProcedure
 * 
 * @package Sofort\Procedures
 * @deprecated v1.0.2
 */
class RefundEventProcedure
{

	use Loggable;

	const ORDER_TYPE_ID_SALES = 1;
	const ORDER_TYPE_ID_CREDIT_NOTE = 4;

	/**
	 * @var int $refundStatus
	 */
	private $refundStatus = Payment::STATUS_REFUNDED;

	/**
	 * @param EventProceduresTriggered $eventTriggered
	 * @param PaymentService $paymentService
	 * @param PaymentRepositoryContract $paymentContract
	 * @param PaymentHelper $paymentHelper
	 * @param ConfigRepository $configRepo
	 * @throws \Exception
	 */
	public function run(EventProceduresTriggered $eventTriggered,
						PaymentService $paymentService,
						PaymentRepositoryContract $paymentContract,
						PaymentHelper $paymentHelper,
						ConfigRepository $configRepo)
	{
		/* @var $order Order */
		$order = $eventTriggered->getOrder();
		$this->getLogger(__METHOD__)->debug('SOFORT::Events.refundEventOrder', ['order' => $order]);

		/* @var $orderAmount OrderAmount */
		$orderAmount = $order->amounts[0];

		switch ($order->typeId) {

			case self::ORDER_TYPE_ID_SALES:

				$orderId = $order->id;

				break;

			case self::ORDER_TYPE_ID_CREDIT_NOTE:

				$parentOrder = $this->getParentOrder($order->orderReferences);
				$this->getLogger(__METHOD__)->debug('SOFORT::General.order', ['parentOrder' => $parentOrder]);

				if ($parentOrder instanceof Order) {
					if ($parentOrder->typeId === self::ORDER_TYPE_ID_SALES) {
						$orderId = $parentOrder->id;
					} else {
						$rootOrder = $this->getParentOrder($parentOrder->orderReferences);
						if ($rootOrder instanceof Order) {
							$orderId = $rootOrder->id;
						}
					}
					$this->setRefundStatus($orderAmount, $parentOrder->amounts[0]);
				}
				break;

		}

		if (empty($orderId)) {
			throw new \Exception('Refunding Sofort payment failed! The given order is invalid!');
		}

		$payments = $paymentContract->getPaymentsByOrderId($orderId);
		$this->getLogger(__METHOD__)->debug('SOFORT::Events.refundEventPayments', ['payments' => $payments]);

		/* @var $payment Payment */
		foreach ($payments as $payment) {

			if ($payment->mopId == $paymentHelper->getSofortMopId()) {

				$refundProperties['transactionId'] = $paymentHelper->getPaymentPropertyValue($payment, PaymentProperty::TYPE_TRANSACTION_ID);
				$refundProperties['holder'] = $configRepo->get('SOFORT.recipientHolder', '');
				$refundProperties['iban'] = $configRepo->get('SOFORT.recipientIBAN', '');
				$refundProperties['bic'] = $configRepo->get('SOFORT.recipientBIC', '');
				$refundProperties['amount'] = $orderAmount->invoiceTotal;
				$refundProperties['currency'] = $orderAmount->currency;
				$this->getLogger(__METHOD__)->debug('SOFORT::Events.refundEventProperties', ['refundProperties' => $refundProperties]);

				if (!empty($refundProperties)) {

					$refundResult = $paymentService->refundPayment($refundProperties);
					if ($refundResult['error']) {
						throw new \Exception($refundResult['error_msg']);
					}

					if ($refundResult['status'] === 'error') {
						throw new \Exception($refundResult['refundError']);
					}

					$paymentData = [
						'status' => $refundResult['status'],
						'amount' => $refundResult['amount'],
						'currency' => $payment->currency,
						'entryDate' => $refundResult['entryDate']
					];
					$additionalData = [];
					$additionalData['parentId'] = $payment->id;
					$additionalData['type'] = Payment::PAYMENT_TYPE_DEBIT;

					$debitPayment = $paymentHelper->createPlentyPayment($paymentData, $additionalData);

					$payment->status = $this->getRefundStatus();
					$paymentContract->updatePayment($payment);

					if (isset($debitPayment) && $debitPayment instanceof Payment) {
						$paymentHelper->assignPlentyPaymentToPlentyOrder($debitPayment, $orderId);
					}
					unset($refundProperties);
				}
			}
		}
	}

	/**
	 * @param array $references
	 * @return mixed
	 */
	private function getParentOrder($references)
	{
		/* @var $reference OrderReference */
		foreach ($references as $reference) {
			if ($reference->referenceType === OrderReference::REFERENCE_TYPE_PARENT) {
				return $reference->originOrder;
			}
		}
		return null;
	}

	/**
	 * @return int
	 */
	private function getRefundStatus()
	{
		return $this->refundStatus;
	}

	/**
	 * @param OrderAmount $orderAmount
	 * @param OrderAmount $parentOrderAmount
	 */
	private function setRefundStatus(OrderAmount $orderAmount, OrderAmount $parentOrderAmount)
	{
		if ($orderAmount->invoiceTotal !== $parentOrderAmount->invoiceTotal) {
			$this->refundStatus = Payment::STATUS_PARTIALLY_REFUNDED;
		} else {
			$this->refundStatus = Payment::STATUS_REFUNDED;
		}
	}
}