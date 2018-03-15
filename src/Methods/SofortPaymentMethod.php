<?php
namespace Sofort\Methods;

use Plenty\Modules\Basket\Contracts\BasketRepositoryContract;
use Plenty\Modules\Basket\Models\Basket;
use Plenty\Modules\Frontend\Contracts\Checkout;
use Plenty\Modules\Order\Shipping\Countries\Contracts\CountryRepositoryContract;
use Plenty\Modules\Payment\Method\Contracts\PaymentMethodService;
use Plenty\Plugin\ConfigRepository;
use Plenty\Plugin\Log\Loggable;
use Sofort\Utility\DivUtility;

/**
 * Class SofortPaymentMethod
 * 
 * @package Sofort\Methods
 */
class SofortPaymentMethod extends PaymentMethodService
{

	use Loggable;

	/**
	 * @var BasketRepositoryContract
	 */
	private $basketRepo;

	/**
	 * @var ConfigRepository
	 */
	private $configRepo;

	/**
	 * @var DivUtility
	 */
	private $divUtility;

	/**
	 * @var string 
	 */
	private $deliveryCountry;

	/**
	 * @var string
	 */
	private $selectedLanguage;

	/**
	 * @var array
	 */
	private $strings = [
		'de' => [
			'name' => 'Sofort.',
			'desc' => 'Einfach und direkt bezahlen.',
			'url' => 'https://documents.sofort.com/sue/kundeninformationen/'
		],
		'en' => [
			'name' => 'Online bank transfer.',
			'desc' => 'Simple and secure',
			'url' => 'https://documents.sofort.com/sb/customer-information/'
		]
	];

	/**
	 * SofortPaymentMethod constructor.
	 *
	 * @param BasketRepositoryContract $basketRepo
	 * @param ConfigRepository $configRepo
	 * @param CountryRepositoryContract $countryRepo
	 * @param Checkout $checkout
	 * @param DivUtility $divUtility
	 */
	public function __construct(BasketRepositoryContract $basketRepo,
								ConfigRepository $configRepo,
								CountryRepositoryContract $countryRepo,
								Checkout $checkout,
								DivUtility $divUtility)
	{
		$this->basketRepo = $basketRepo;
		$this->configRepo = $configRepo;
		$this->deliveryCountry = $countryRepo->findIsoCode($checkout->getShippingCountryId(), 'iso_code_2');
		$this->divUtility = $divUtility;
		$this->selectedLanguage = $divUtility->getLanguage();
	}

	/**
	 * @return bool
	 */
	public function isActive(): bool
	{
		if ($this->configRepo->get('SOFORT.configKey')) {
			if ($this->configRepo->get('SOFORT.activatePayment', 'false') !== 'false') {
				if ($this->isDeliveryCountryAllowed($this->deliveryCountry)) {
					/* @var $basket Basket */
					$basket = $this->basketRepo->load();
					if ($basket->itemSum > 0) {
						return true;
					}
				}
			}
		}
		return false;
	}

	/**
	 * @return string
	 */
	public function getName(): string
	{
		// Name of the payment method
		$name = $this->strings[$this->selectedLanguage]['name'];

		return $name;
	}

	/**
	 * @return string
	 */
	public function getDescription(): string
	{
		$desc = $this->strings[$this->selectedLanguage]['desc'];

		return $desc;
	}

	/**
	 * @return string
	 */
	public function getSourceUrl(): string
	{
		$url = $this->strings[$this->selectedLanguage]['url'];

		return $url;
	}

	/**
	 * @return string
	 */
	public function getIcon(): string
	{
		$src = $this->divUtility->getLogo($this->selectedLanguage);

		return $src;
	}

	/**
	 * @return bool
	 */
	public function isSwitchableTo(): bool
	{
		return false;
	}

	/**
	 * @return bool
	 */
	public function isSwitchableFrom(): bool
	{
		return false;
	}

	/**
	 * @param string $country
	 * @return bool
	 */
	private function isDeliveryCountryAllowed($country): bool
	{
		$availableCountries = ['DE', 'AT', 'CH', 'IT', 'EN', 'ES', 'BE', 'PL', 'NL'];
		if (in_array($country, $availableCountries)) {
			return true;
		}
		return false;
	}
}