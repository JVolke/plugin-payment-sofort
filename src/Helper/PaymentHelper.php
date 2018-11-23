<?php
namespace Sofort\Helper;

use Plenty\Modules\Account\Contact\Contracts\ContactRepositoryContract;
use Plenty\Modules\Account\Contact\Models\Contact;
use Plenty\Modules\Frontend\Services\AccountService;
use Plenty\Modules\Helper\Services\WebstoreHelper;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Modules\Order\Models\Order;
use Plenty\Modules\Payment\Contracts\PaymentContactRelationRepositoryContract;
use Plenty\Modules\Payment\Contracts\PaymentOrderRelationRepositoryContract;
use Plenty\Modules\Payment\Contracts\PaymentPropertyRepositoryContract;
use Plenty\Modules\Payment\Contracts\PaymentRepositoryContract;
use Plenty\Modules\Payment\Method\Contracts\PaymentMethodRepositoryContract;
use Plenty\Modules\Payment\Method\Models\PaymentMethod;
use Plenty\Modules\Payment\Models\Payment;
use Plenty\Modules\Payment\Models\PaymentProperty;
use Plenty\Modules\System\Models\WebstoreConfiguration;
use Plenty\Plugin\ConfigRepository;
use Plenty\Plugin\Log\Loggable;
use Sofort\Services\SessionStorageService;

/**
 * Class PaymentHelper
 *
 * @package Sofort\Helper
 */
class PaymentHelper
{

	use Loggable;

	/**
	 * @var PaymentMethodRepositoryContract
	 */
	private $paymentMethodRepository;

	/**
	 * @var ConfigRepository
	 */
	private $config;

	/**
	 * @var SessionStorageService
	 */
	private $sessionService;

	/**
	 * @var PaymentOrderRelationRepositoryContract
	 */
	private $paymentOrderRelationRepo;

	/**
	 * @var PaymentRepositoryContract
	 */
	private $paymentRepository;

	/**
	 * @var OrderRepositoryContract
	 */
	private $orderRepo;

	/**
	 * @var PaymentPropertyRepositoryContract
	 */
	private $paymentPropertyRepository;

	/**
	 * @var PaymentContactRelationRepositoryContract
	 */
	private $paymentContactRelationRepo;

	/**
	 * @var array
	 */
	private $statusMap = [];

	/**
	 * PaymentHelper constructor.
	 *
	 * @param PaymentMethodRepositoryContract $paymentMethodRepository
	 * @param PaymentRepositoryContract $paymentRepo
	 * @param PaymentOrderRelationRepositoryContract $paymentOrderRelationRepo
	 * @param ConfigRepository $config
	 * @param SessionStorageService $sessionService
	 * @param OrderRepositoryContract $orderRepo
	 * @param PaymentPropertyRepositoryContract $paymentPropertyRepository
	 */
	public function __construct(PaymentMethodRepositoryContract $paymentMethodRepository,
								PaymentRepositoryContract $paymentRepo,
								PaymentOrderRelationRepositoryContract $paymentOrderRelationRepo,
								ConfigRepository $config,
								SessionStorageService $sessionService,
								OrderRepositoryContract $orderRepo,
								PaymentPropertyRepositoryContract $paymentPropertyRepository,
								PaymentContactRelationRepositoryContract $paymentContactRelationRepo)
	{
		$this->config = $config;
		$this->sessionService = $sessionService;
		$this->paymentMethodRepository = $paymentMethodRepository;
		$this->paymentOrderRelationRepo = $paymentOrderRelationRepo;
		$this->paymentRepository = $paymentRepo;
		$this->orderRepo = $orderRepo;
		$this->paymentPropertyRepository = $paymentPropertyRepository;
		$this->paymentContactRelationRepo = $paymentContactRelationRepo;
		$this->statusMap = [];
	}

	/**
	 * @return mixed
	 */
	public function getSofortMopId()
	{
		$paymentMethods = $this->paymentMethodRepository->allForPlugin('sofort');
		$this->getLogger(__METHOD__)->debug('SOFORT::General.retrievedValue', ['paymentMethods' => $paymentMethods]);

		/* @var $paymentMethod PaymentMethod */
		foreach ($paymentMethods as $index => $paymentMethod) {
			if ($paymentMethod->paymentKey === 'SOFORT') {
				return $paymentMethod->id;
			}
		}

		return 'no_paymentmethod_found';
	}

	/**
	 * @return array
	 */
	public function getRestReturnUrls(): array
	{
		$domain = $this->getDomain();

		$urls = [];
		$urls['success'] = $domain.'/sofort/checkoutSuccess?transactionId=-TRANSACTION-';
		$urls['cancel'] = $domain.'/sofort/checkoutCancel?transactionId=-TRANSACTION-';
		$urls['notification'] = $domain.'/sofort/notification?transactionId=-TRANSACTION-';

		return $urls;
	}

	/**
	 * @return string
	 */
	public function getDomain(): string
	{
		/** @var WebstoreHelper $webstoreHelper */
		$webstoreHelper = pluginApp(WebstoreHelper::class);

		/** @var WebstoreConfiguration $webstoreConfig */
		$webstoreConfig = $webstoreHelper->getCurrentWebstoreConfiguration();

		return $webstoreConfig->domainSsl;
	}

	/**
	 * @param array $paymentData
	 * @param array $additionalData
	 * @return Payment
	 */
	public function createPlentyPayment(array $paymentData, $additionalData = []): Payment
	{
		/* @var $preparePayment Payment */
		$preparePayment = pluginApp(Payment::class);

		$preparePayment->mopId = (int) $this->getSofortMopId();
		$preparePayment->transactionType = Payment::TRANSACTION_TYPE_BOOKED_POSTING;
		$preparePayment->status = $this->mapStatus((string) $paymentData['status']);
		$preparePayment->currency = $paymentData['currency'];
		$preparePayment->amount = $paymentData['amount'];
		$preparePayment->receivedAt = $paymentData['entryDate'];
		$preparePayment->unaccountable = 1;

		$preparePayment->hash = md5('sofort_'.time());

		if (!empty($additionalData['type'])) {
			$preparePayment->type = $additionalData['type'];
		}

		if (!empty($additionalData['parentId'])) {
			$preparePayment->parentId = $additionalData['parentId'];
		}

		$paymentProperty = array();
		$paymentProperty[] = $this->getPaymentProperty(PaymentProperty::TYPE_BOOKING_TEXT, $paymentData['transactionId']);
		$paymentProperty[] = $this->getPaymentProperty(PaymentProperty::TYPE_TRANSACTION_ID, $paymentData['transactionId']);
		$paymentProperty[] = $this->getPaymentProperty(PaymentProperty::TYPE_EXTERNAL_TRANSACTION_STATUS, $paymentData['status']);
		$paymentProperty[] = $this->getPaymentProperty(PaymentProperty::TYPE_ACCOUNT_HOLDER_OF_SENDER, $paymentData['senderHolder']);
		$paymentProperty[] = $this->getPaymentProperty(PaymentProperty::TYPE_IBAN_OF_SENDER, $paymentData['senderIBAN']);
		$paymentProperty[] = $this->getPaymentProperty(PaymentProperty::TYPE_BIC_OF_SENDER, $paymentData['senderBIC']);
		$paymentProperty[] = $this->getPaymentProperty(PaymentProperty::TYPE_NAME_OF_RECEIVER, $paymentData['recipientHolder']);
		$paymentProperty[] = $this->getPaymentProperty(PaymentProperty::TYPE_IBAN_OF_RECEIVER, $paymentData['recipientIBAN']);
		$paymentProperty[] = $this->getPaymentProperty(PaymentProperty::TYPE_BIC_OF_RECEIVER, $paymentData['recipientBIC']);
		$paymentProperty[] = $this->getPaymentProperty(PaymentProperty::TYPE_PAYMENT_TEXT, $paymentData['reason']);
		$paymentProperty[] = $this->getPaymentProperty(PaymentProperty::TYPE_ORIGIN, Payment::ORIGIN_PLUGIN);

		$this->getLogger(__METHOD__)->debug('SOFORT::General.paymentProperties', ['result' => $paymentProperty]);

		$preparePayment->properties = $paymentProperty;

		try {
			/* @var $payment Payment */
			$payment = $this->paymentRepository->createPayment($preparePayment);
		} catch (\Exception $exc) {
			$this->getLogger(__METHOD__)->error('SOFORT::General.createPayment', ['trace' => $exc->getTraceAsString()]);
		}

		return $payment;
	}

	/**
	 * @param int $typeId
	 * @param mixed $value
	 * @param string $id
	 * @return PaymentProperty
	 */
	private function getPaymentProperty($typeId, $value, $id = '0')
	{
		/* @var $paymentProperty PaymentProperty */
		$paymentProperty = pluginApp(PaymentProperty::class);

		$paymentProperty->typeId = $typeId;
		$paymentProperty->value = (string) $value;
		$paymentProperty->id = $id;

		return $paymentProperty;
	}

	/**
	 * @param string $status
	 * @param string $transactionId
	 */
	public function updatePayment($status, $transactionId)
	{
		$payments = $this->paymentRepository->getPaymentsByPropertyTypeAndValue(PaymentProperty::TYPE_TRANSACTION_ID, $transactionId);
		$state = $this->mapStatus((string) $status);

		/* @var $payment Payment */
		foreach ($payments as $payment) {
			$this->getLogger(__METHOD__)->info('SOFORT::General.payments', ['payment' => $payment]);
			if ($payment->mopId == $this->getSofortMopId()) {
				if ($payment->status != $state) {
					$this->getLogger(__METHOD__)->debug('SOFORT::General.updatePayment', ['status' => $state]);
					$payment->status = $state;
					if ($state == Payment::STATUS_APPROVED || $state == Payment::STATUS_CAPTURED) {
						$payment->unaccountable = 0;
					}
					try {
						$this->paymentRepository->updatePayment($payment);
					} catch (\Exception $exc) {
						$this->getLogger(__METHOD__)->error('SOFORT::General.updatePayment', ['payment' => $payment, 'trace' => $exc->getTraceAsString()]);
					}
				}
				$properties = $payment->properties;
				if (!empty($properties)) {
					/* @var $property PaymentProperty */
					foreach ($properties as $property) {
						if ($property->typeId == PaymentProperty::TYPE_EXTERNAL_TRANSACTION_STATUS) {
							$this->getLogger(__METHOD__)->debug('SOFORT::General.changePaymentProperty', ['value' => $status]);
							$property->value = (string) $status;
							try {
								$this->paymentPropertyRepository->changeProperty($property);
							} catch (\Exception $exc) {
								$this->getLogger(__METHOD__)->error('SOFORT::General.changePaymentProperty', ['property' => $property, 'trace' => $exc->getTraceAsString()]);
							}
						}
					}
				}
			}
		}
	}

	/**
	 * @param Payment $payment
	 * @param int $orderId
	 */
	public function assignPlentyPaymentToPlentyOrder(Payment $payment, int $orderId)
	{
		/* @var $order Order */
		$order = $this->orderRepo->findOrderById($orderId);
		$this->getLogger(__METHOD__)->debug('SOFORT::General.order', ['order' => $order]);

		try {
			$this->paymentOrderRelationRepo->createOrderRelation($payment, $order);
		} catch (\Exception $exc) {
			$this->getLogger(__METHOD__)->error('SOFORT::General.createOrderRelation', ['trace' => $exc->getTraceAsString()]);
		}

		$this->assignPlentyPaymentToPlentyContact($payment);
	}

	/**
	 * @param Payment $payment
	 */
	private function assignPlentyPaymentToPlentyContact(Payment $payment)
	{
		/* @var $accountService AccountService */
		$accountService = pluginApp(AccountService::class);

		$contactId = $accountService->getAccountContactId();
		$this->getLogger(__METHOD__)->debug('SOFORT::General.retrievedValue', ['contactId' => $contactId]);

		if (!empty($contactId) && $contactId > 0) {

			/* @var $contactRepo ContactRepositoryContract */
			$contactRepo = pluginApp(ContactRepositoryContract::class);

			/* @var $contact Contact */
			$contact = $contactRepo->findContactById($contactId);
			$this->getLogger(__METHOD__)->debug('SOFORT::General.contact', ['contact' => $contact]);

			try {
				$this->paymentContactRelationRepo->createContactRelation($payment, $contact);
			} catch (\Exception $exc) {
				$this->getLogger(__METHOD__)->error('SOFORT::General.createContactRelation', ['trace' => $exc->getTraceAsString()]);
			}
		}
	}

	/**
	 * @param string $status
	 * @return int
	 */
	public function mapStatus(string $status)
	{
		if (!is_array($this->statusMap) || count($this->statusMap) <= 0) {

			$statusConstants = $this->paymentRepository->getStatusConstants();

			if (!is_null($statusConstants) && is_array($statusConstants)) {
				$this->statusMap['received'] = $statusConstants['approved'];
				$this->statusMap['loss'] = $statusConstants['refused'];
				$this->statusMap['pending'] = $statusConstants['awaiting_approval'];
				$this->statusMap['refunded'] = $statusConstants['refunded'];
				$this->statusMap['untraceable'] = $statusConstants['awaiting_approval'];
				$this->statusMap['accepted'] = $statusConstants['approved'];
			}
		}

		return (int) $this->statusMap[$status];
	}

	/**
	 * @param Payment $payment
	 * @param int $propertyType
	 * @return null|string
	 */
	public function getPaymentPropertyValue($payment, $propertyType)
	{
		$properties = $payment->properties;
		if (!empty($properties)) {
			/* @var $property PaymentProperty */
			foreach ($properties as $property) {
				if ($property instanceof PaymentProperty) {
					if ($property->typeId == $propertyType) {
						return $property->value;
					}
				}
			}
		}
		return null;
	}
}
