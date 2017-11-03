<?php
namespace Sofort\Services;

use Plenty\Modules\Account\Address\Contracts\AddressRepositoryContract;
use Plenty\Modules\Account\Address\Models\Address;
use Plenty\Modules\Basket\Models\Basket;
use Plenty\Modules\Frontend\Services\AccountService;
use Plenty\Modules\Order\Models\Order;
use Plenty\Modules\Order\Shipping\Countries\Contracts\CountryRepositoryContract;
use Plenty\Modules\Payment\Contracts\PaymentRepositoryContract;
use Plenty\Modules\Payment\Events\Checkout\GetPaymentMethodContent;
use Plenty\Modules\Payment\Method\Contracts\PaymentMethodRepositoryContract;
use Plenty\Modules\Plugin\Libs\Contracts\LibraryCallContract;
use Plenty\Plugin\ConfigRepository;
use Plenty\Plugin\Log\Loggable;
use Plenty\Plugin\Templates\Twig;
use Sofort\Constants\SofortConstants;
use Sofort\Helper\PaymentHelper;
use Sofort\Services\SessionStorageService;
use Sofort\Utility\DivUtility;

/**
 * @package Sofort\Services
 */
class PaymentService
{

	use Loggable;

	/**
	 * @var string
	 */
	private $returnType = '';

	/**
	 * @var PaymentMethodRepositoryContract
	 */
	private $paymentMethodRepository;

	/**
	 * @var PaymentRepositoryContract
	 */
	private $paymentRepository;

	/**
	 * @var PaymentHelper
	 */
	private $paymentHelper;

	/**
	 * @var LibraryCallContract
	 */
	private $libCall;

	/**
	 * @var AddressRepositoryContract
	 */
	private $addressRepo;

	/**
	 * @var ConfigRepository
	 */
	private $config;

	/**
	 * @var SessionStorageService
	 */
	private $sessionStorage;

	/**
	 * @var Twig
	 */
	private $twig;

	/**
	 * PaymentService constructor.
	 *
	 * @param PaymentMethodRepositoryContract $paymentMethodRepository
	 * @param PaymentRepositoryContract $paymentRepository
	 * @param ConfigRepository $config
	 * @param PaymentHelper $paymentHelper
	 * @param LibraryCallContract $libCall
	 * @param AddressRepositoryContract $addressRepo
	 * @param SessionStorageService $sessionStorage
	 * @param Twig $twig
	 */
	public function __construct(PaymentMethodRepositoryContract $paymentMethodRepository,
								PaymentRepositoryContract $paymentRepository,
								ConfigRepository $config,
								PaymentHelper $paymentHelper,
								LibraryCallContract $libCall,
								AddressRepositoryContract $addressRepo,
								SessionStorageService $sessionStorage,
								Twig $twig)
	{
		$this->paymentMethodRepository = $paymentMethodRepository;
		$this->paymentRepository = $paymentRepository;
		$this->paymentHelper = $paymentHelper;
		$this->libCall = $libCall;
		$this->addressRepo = $addressRepo;
		$this->config = $config;
		$this->sessionStorage = $sessionStorage;
		$this->twig = $twig;
	}

	/**
	 * @return string
	 */
	public function getReturnType()
	{
		return $this->returnType;
	}

	/**
	 * @param array
	 * @return string
	 */
	public function getPaymentContent($sofortRequestParams): string
	{
		$paymentResult = $this->libCall->call('SOFORT::preparePayment', $sofortRequestParams);
		$this->getLogger(__METHOD__)->debug('SOFORT::General.retrievedValue', ['params' => $sofortRequestParams, 'result' => $paymentResult]);

		if (is_array($paymentResult) && $paymentResult['error']) {
			$this->returnType = GetPaymentMethodContent::RETURN_TYPE_HTML;
			$content = $this->twig->render('SOFORT::Error');
			return $content;
		}

		if (isset($paymentResult['transactionId']) && strlen($paymentResult['transactionId'])) {
			$this->sessionStorage->setSessionValue(SessionStorageService::SOFORT_PAY_ID, $paymentResult['transactionId']);
		}

		$paymentContent = $paymentResult['paymentUrl'];
		$this->returnType = GetPaymentMethodContent::RETURN_TYPE_REDIRECT_URL;

		if (!$paymentContent) {
			$this->returnType = GetPaymentMethodContent::RETURN_TYPE_HTML;
			$content = $this->twig->render('SOFORT::Error');
			return $content;
		}

		return $paymentContent;
	}

	/**
	 * @param Basket $basket
	 * @return string
	 */
	public function getPaymentContentByBasket(Basket $basket): string
	{
		return $this->getPaymentContent($this->getSofortParamsByBasket($basket));
	}

	/**
	 * @param Order $order
	 * @return string
	 * @deprecated since version 1.0.0
	 */
	public function getPaymentContentByOrder(Order $order): string
	{
		return $this->getPaymentContent($this->getSofortParamsByOrder($order));
	}

	/**
	 * @return array
	 */
	public function executePayment()
	{
		$transactionId = $this->sessionStorage->getSessionValue(SessionStorageService::SOFORT_PAY_ID);

		$params = $this->getApiContextParams();
		$params['transactionId'] = $transactionId;

		$response = $this->libCall->call('SOFORT::getTransaction', $params);
		$this->getLogger(__METHOD__)->debug('SOFORT::General.libCall', ['getTransaction' => $response]);
		if (is_array($response) && $response['error']) {
			$this->returnType = 'errorCode';
			return $response['error_msg'];
		}

		$this->sessionStorage->setSessionValue(SessionStorageService::SOFORT_PAY_ID, null);

		return $response;
	}

	/**
	 * @param string $requestContent
	 * @return array
	 */
	public function notificationStatus($requestContent): array
	{
		$requestParams['requestContent'] = $requestContent;
		$notificationResult = $this->libCall->call('SOFORT::notification', $requestParams);
		if ($notificationResult['error']) {
			return $notificationResult;
		}

		$executeParams = $this->getApiContextParams();
		$executeParams['transactionId'] = $notificationResult['transactionId'];
		$executeParams['status'] = true;

		$transactionData = $this->libCall->call('SOFORT::getTransaction', $executeParams);
		$this->getLogger(__METHOD__)->debug('SOFORT::General.libCall', ['getTransaction' => $transactionData]);

		return $transactionData;
	}

	/**
	 * @param array $refundRequestData
	 * @return array
	 * @deprecated v1.0.2
	 */
	public function refundPayment($refundRequestData): array
	{
		$apiContext = $this->getApiContextParams();
		$requestParams = array_merge($apiContext, $refundRequestData);

		$response = $this->libCall->call('SOFORT::refundPayment', $requestParams);
		$this->getLogger(__METHOD__)->debug('SOFORT::General.libCall', ['refundPayment' => $response]);

		return $response;
	}

	/**
	 * @param Basket $basket
	 * @return array
	 */
	private function getSofortParamsByBasket(Basket $basket): array
	{
		$this->getLogger(__METHOD__)->info('SOFORT::General.triggerFunction', ['basket' => $basket]);

		$sofortRequestParams = $this->getApiContextParams();

		/* @var $accountService AccountService */
		$accountService = pluginApp(AccountService::class);

		// Get customer details from basket
		$billingAddressId = $basket->customerInvoiceAddressId;
		if ($billingAddressId) {
			/* @var $addressRepo AddressRepositoryContract */
			$addressRepo = pluginApp(AddressRepositoryContract::class);
			/* @var $address Address */
			$address = $addressRepo->findAddressById($billingAddressId);
			$this->getLogger(__METHOD__)->debug('SOFORT::General.retrievedValue', ['address' => $address]);
			$customerName = $address->name2.' '.$address->name3;
			$customerCompany = $address->companyName;
			$customerEmail = $address->email;
		}

		$replacement1 = [
			'{{transaction_id}}' => '-TRANSACTION-',
			'{{customer_name}}' => $customerName,
			'{{customer_email}}' => $customerEmail
		];
		$reason1 = $this->config->get('SOFORT.reason1');
		if(!strlen($reason1)) {
			$reason1 .= '{{transaction_id}}';
		}
		$reason1 = str_replace(array_keys($replacement1), array_values($replacement1), $reason1);
		$replacement2 = [
			'{{customer_id}}' => (string) $accountService->getAccountContactId(), // Get the contact details (registered users only)
			'{{customer_company}}' => $customerCompany,
			'{{order_id}}' => '', // unsupported
			'{{order_date}}' => '' // unsupported
		];
		$replacementAll = array_merge($replacement1, $replacement2);
		$reason2 = $this->config->get('SOFORT.reason2');
		$reason2 = str_replace(array_keys($replacementAll), array_values($replacementAll), $reason2);

		/* @var $divUtility DivUtility */
		$divUtility = pluginApp(DivUtility::class);
		$reason1 = $divUtility->removeInvalidChars($reason1);
		$reason2 = $divUtility->removeInvalidChars($reason2);

		$sofortRequestParams['reasons'] = [
			'reason1' => substr($reason1, 0, 26),
			'reason2' => substr($reason2, 0, 26)
		];

		$sofortRequestParams['amount'] = $basket->basketAmount;
		$sofortRequestParams['currency'] = $basket->currency;

		$countryRepo = pluginApp(CountryRepositoryContract::class);
		$sofortRequestParams['country'] = $countryRepo->findIsoCode($basket->shippingCountryId, 'iso_code_2');

		$sofortRequestParams['urls'] = $this->paymentHelper->getRestReturnUrls();

		$this->getLogger(__METHOD__)->debug('SOFORT::General.retrievedValue', ['sofortRequestParams' => $sofortRequestParams]);

		return $sofortRequestParams;
	}

	/**
	 * @param Order $order
	 * @return array
	 * @deprecated since version 1.0.0
	 */
	private function getSofortParamsByOrder(Order $order): array
	{
		$this->getLogger(__METHOD__)->info('SOFORT::General.triggerFunction', ['order' => $order]);

		$sofortRequestParams = $this->getApiContextParams();

		$sofortRequestParams['orderId'] = $order->id;
		$amount = $order->amounts->first();
		$sofortRequestParams['amount'] = $amount->invoiceTotal;
		$sofortRequestParams['currency'] = $amount->currency;

		$shippingAddressId = $order->deliveryAddress->id;

		$shippingAddress = $order->billingAddress;
		if (!is_null($shippingAddressId)) {
			if ($shippingAddressId != -99 && $shippingAddressId > 0) {
				$shippingAddress = $order->deliveryAddress;
			}
			$sofortRequestParams['emailCustomer'] = $shippingAddress->email;
		}

		$countryRepo = pluginApp(CountryRepositoryContract::class);
		$sofortRequestParams['country'] = $countryRepo->findIsoCode($shippingAddress->countryId, 'iso_code_2');

		$sofortRequestParams['urls'] = $this->paymentHelper->getRestReturnUrls();
		$this->getLogger(__METHOD__)->debug('SOFORT::General.retrievedValue', ['sofortRequestParams' => $sofortRequestParams]);

		return $sofortRequestParams;
	}

	/**
	 * @return array
	 */
	private function getApiContextParams(): array
	{
		$apiContextParams = [];
		$apiContextParams['configKey'] = $this->config->get('SOFORT.configKey');
		$apiContextParams['pluginVersion'] = SofortConstants::PLUGIN_VERSION;

		return $apiContextParams;
	}
}