<?php
/*
* 2007-2015 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author PrestaShop SA <contact@prestashop.com>
*  @copyright  2007-2015 PrestaShop SA
*  @license    http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/
//check if the SDK nieeds to be loaded
if (!class_exists('\Paynl\Paymentmethods')) {
    $autoload_location = __DIR__ . '/vendor/autoload.php';
    if (file_exists($autoload_location)) {
        require_once $autoload_location;
    }
}

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

if (!defined('_PS_VERSION_')) {
    exit;
}

class PaynlPaymentMethods extends PaymentModule
{
    protected $_html = '';
    protected $_postErrors = array();
    private $statusPending;
    private $statusPaid;
    private $statusRefund;
    private $statusCanceled;
    private $paymentMethods;

    public function __construct()
    {
        $this->name = 'paynlpaymentmethods';
        $this->tab = 'payments_gateways';
        $this->version = '4.2.6';

        $this->ps_versions_compliancy = array('min' => '1.7', 'max' => _PS_VERSION_);
        $this->author = 'Pay.nl';
        $this->controllers = array('startPayment', 'finish', 'exchange');
        $this->is_eu_compatible = 1;

        $this->currencies = true;
        $this->currencies_mode = 'checkbox';

        $this->bootstrap = true;
        parent::__construct();
        $this->statusPending = Configuration::get('PS_OS_CHEQUE');
        $this->statusPaid = Configuration::get('PS_OS_PAYMENT');
        $this->statusCanceled = Configuration::get('PS_OS_CANCELED');
        $this->statusRefund = Configuration::get('PS_OS_REFUND');

        $this->displayName = $this->l('Pay.nl');
        $this->description = $this->l('Add many payment methods to your webshop');

        if (!count(Currency::checkPaymentCurrencies($this->id))) {
            $this->warning = $this->l('No currency has been set for this module.');
        }

    }

    public function install()
    {

        if (!parent::install()
            || !$this->registerHook('paymentOptions')
            || !$this->registerHook('paymentReturn')
        ) {
            return false;
        }


        $this->createPaymentFeeProduct();

        return true;
    }

    public function createPaymentFeeProduct()
    {
        $id_product = Configuration::get('PAYNL_FEE_PRODUCT_ID');
        $feeProduct = new Product(Configuration::get('PAYNL_FEE_PRODUCT_ID'), true);

        // check if paymentfee product exists
        if ( ! $id_product || ! $feeProduct->id) {
            $objProduct               = new Product();
            $objProduct->price        = 0;
            $objProduct->is_virtual   = 1;
            $objProduct->out_of_stock = 2;
            $objProduct->visibility = 'none';

            foreach (Language::getLanguages() as $language) {
                $objProduct->name[$language['id_lang']] = $this->l('Payment fee');
                $objProduct->link_rewrite[$language['id_lang']] = Tools::link_rewrite($objProduct->name[$language['id_lang']]);
            }

            if ($objProduct->add()) {
                //allow buy product out of stock
                StockAvailable::setProductDependsOnStock($objProduct->id, false);
                StockAvailable::setQuantity($objProduct->id, $objProduct->getDefaultIdProductAttribute(), 9999999);
                StockAvailable::setProductOutOfStock($objProduct->id, true);

                //update product id
                $id_product = $objProduct->id;
                Configuration::updateValue('PAYNL_FEE_PRODUCT_ID', $id_product);
            }
        }

        return true;
    }

    public function installOverrides()
    {
        // This version doesn't have overrides anymode, but prestashop still keeps them around.
        // By overriding this method we can prevent prestashop from reinstalling the old overrides
        return true;
    }

    public function uninstall()
    {

        if (parent::uninstall()) {

            Configuration::deleteByName('PAYNL_FEE_PRODUCT_ID');

        }

        return true;
    }


    public function hookPaymentOptions($params)
    {
        if (!$this->active) {
            return false;
        }

        if (isset($params['cart']) && !$this->checkCurrency($params['cart'])) {
            return false;
        }
        $cart = null;
        if (isset($params['cart'])) {
            $cart = $params['cart'];
        }
        $payment_options = $this->getPaymentMethods($cart);

        return $payment_options;
    }

    public function checkCurrency($cart)
    {
        $currency_order = new Currency($cart->id_currency);
        $currencies_module = $this->getCurrency($cart->id_currency);

        if (is_array($currencies_module)) {
            foreach ($currencies_module as $currency_module) {
                if ($currency_order->id == $currency_module['id_currency']) {
                    return true;
                }
            }
        }

        return false;
    }

    private function getPaymentMethods($cart = null)
    {
        /**
         * @var $cart Cart
         */
        $availablePaymentMethods = $this->getPaymentMethodsForCart($cart);

        $paymentmethods = [];
        foreach ($availablePaymentMethods as $paymentMethod) {
            $objPaymentMethod = new PaymentOption();

            $objPaymentMethod->setCallToActionText($paymentMethod->name)
                ->setAction($this->context->link->getModuleLink($this->name, 'startPayment', array(),
                    true))
                ->setInputs([
                    'payment_option_id' => [
                        'name' => 'payment_option_id',
                        'type' => 'hidden',
                        'value' => $paymentMethod->id,
                    ],
                ])
                ->setLogo('https://www.pay.nl/images/payment_profiles/50x32/' . $paymentMethod->id . '.png');
            if (isset($paymentMethod->description)) {
                $objPaymentMethod->setAdditionalInformation('<p>' . $paymentMethod->description . '</p>');
            }

            if ($paymentMethod->id == 10) {
                $objPaymentMethod->setForm($this->getBanksForm($paymentMethod->id));
            }
            $paymentmethods[] = $objPaymentMethod;
        }

        return $paymentmethods;
    }


    /**
     * @param $cart Cart
     * @param $paymentMethodId int
     * @param $cartTotal float
     * @return bool
     */
    public function isPaymentMethodAvailable($cart, $paymentMethodId, $cartTotal = null)
    {
        if (is_null($cartTotal)) {
            $cartTotal = $cart->getOrderTotal(true, Cart::BOTH);
        }

        $paymentMethods = json_decode(Configuration::get('PAYNL_PAYMENTMETHODS'));

        $paymentMethod = array_filter($paymentMethods, function ($value) use ($paymentMethodId) {
            return $value->id == $paymentMethodId;
        });

        if (empty($paymentMethod)) {
            return false;
        }

        $paymentMethod = array_pop($paymentMethod);

        if (!isset($paymentMethod->enabled) || $paymentMethod->enabled == false) {
            return false;
        }

        $paymentFee = $this->getPaymentFee($paymentMethod, $cartTotal);
        $totalWithFee = $cartTotal + $paymentFee;

        // check min and max amount
        if (!empty($paymentMethod->min_amount) && $totalWithFee < $paymentMethod->min_amount) {
            return false;
        }
        if (!empty($paymentMethod->max_amount) && $totalWithFee > $paymentMethod->max_amount) {
            return false;
        }

        // check country
        if (isset($paymentMethod->limit_countries) && $paymentMethod->limit_countries == 1) {
            $address = new Address($cart->id_address_delivery);
            $address->id_country;
            $allowed_countries = $paymentMethod->allowed_countries;
            if (!in_array($address->id_country, $allowed_countries)) {
                return false;
            }
        }

        // check carriers
        if (isset($paymentMethod->limit_carriers) && $paymentMethod->limit_carriers == 1) {
            $allowed_carriers = $paymentMethod->allowed_carriers;
            if (!in_array($cart->id_carrier, $allowed_carriers)) {
                return false;
            }
        }

        return true;
    }

    private function getPaymentMethodsForCart($cart = null)
    {
        /**
         * @var $cart Cart
         */
        // Return listed payment methods if already checked
        if (isset($this->paymentMethods) && count($this->paymentMethods) > 0) {
            return $this->paymentMethods;
        }

        $paymentMethods = json_decode(Configuration::get('PAYNL_PAYMENTMETHODS'));
        if ($cart === null) {
            $this->paymentMethods = $paymentMethods;

            return $paymentMethods;
        }

        $cartTotal = $cart->getOrderTotal(true, Cart::BOTH);

        $result = array();
        foreach ($paymentMethods as $paymentMethod) {
            if ($this->isPaymentMethodAvailable($cart, $paymentMethod->id, $cartTotal)) {
                $strFee = "";
                // Show payment fee
                $paymentMethod->fee = $this->getPaymentFee($paymentMethod, $cartTotal);

                if ($paymentMethod->fee > 0) {
                    $strFee = " (+ " . Tools::displayPrice($paymentMethod->fee, (int)$cart->id_currency, true) . ")";
                }

                $paymentMethod->name .= $strFee;

                $result[] = $paymentMethod;
            }
        }
        $this->paymentMethods = $result;

        return $result;
    }

    /**
     * @param $objPaymentMethod
     * @param int $cartTotal
     * @param bool $processFee
     *
     * @return string
     */
    public function getPaymentFee($objPaymentMethod, $cartTotal)
    {

        $iFee = 0;
        if (isset($objPaymentMethod->fee_value)) {
            if (isset($objPaymentMethod->fee_percentage) && $objPaymentMethod->fee_percentage == true) {
                $iFee = (float)($cartTotal * $objPaymentMethod->fee_value / 100);

            } else {
                $iFee = (float)$objPaymentMethod->fee_value;
            }
        }

        return $iFee;
    }

    private function getBanksForm($payment_option_id)
    {
        $this->sdkLogin();
        $banks = \Paynl\Paymentmethods::getBanks($payment_option_id);

        $this->context->smarty->assign([
            'action' => $this->context->link->getModuleLink($this->name, 'startPayment', array(), true),
            'banks' => $banks,
            'payment_option_id' => $payment_option_id,
        ]);

        return $this->context->smarty->fetch('module:paynlpaymentmethods/views/templates/front/payment_form_ideal.tpl');
    }

    private function sdkLogin()
    {
        $apitoken = Tools::getValue('PAYNL_API_TOKEN', Configuration::get('PAYNL_API_TOKEN'));
        $serviceId = Tools::getValue('PAYNL_SERVICE_ID', Configuration::get('PAYNL_SERVICE_ID'));
        \Paynl\Config::setApiToken($apitoken);
        \Paynl\Config::setServiceId($serviceId);
    }

    /**
     * @param $transactionId
     * @param null $message
     *
     * @return \Paynl\Result\Transaction\Transaction
     * @throws Exception
     */
    public function processPayment($transactionId, &$message = null)
    {
        $transaction = $this->getTransaction($transactionId);

        $order_state = $this->statusPending;
        if ($transaction->isPaid() || $transaction->isAuthorized()) {
            $order_state = $this->statusPaid;
        } elseif ($transaction->isCanceled()) {
            $order_state = $this->statusCanceled;
        }
        if ($transaction->isRefunded(false)) {
            $order_state = $this->statusRefund;
        }

        /**
         * @var $orderState OrderStateCore
         */
        $orderState = new OrderState($order_state);
        $orderStateName = $orderState->name;
        if (is_array($orderStateName)) {
            $orderStateName = array_pop($orderStateName);
        }

        $cart = new Cart((int)$transaction->getExtra1());

        /**
         * @var $cart CartCore
         */
        if (version_compare(_PS_VERSION_, '1.7.1.0', '>=')) {
            $orderId = Order::getIdByCartId($transaction->getExtra1());
        } else {
            //Deprecated since prestashop 1.7.1.0
            $orderId = Order::getOrderByCartId($transaction->getExtra1());
        }

        if ($orderId) {
            $order = new Order($orderId);

            /**
             * @var $order OrderCore
             */
            if ($order->hasBeenPaid() && !$transaction->isRefunded(false)) {
                $message = 'Order is already paid | OrderReference: ' . $order->reference;

                return $transaction;
            }

            $orderPayment = null;
            $arrOrderPayment = OrderPayment::getByOrderReference($order->reference);
            foreach ($arrOrderPayment as $objOrderPayment) {
                if ($objOrderPayment->transaction_id == $transactionId) {
                    $orderPayment = $objOrderPayment;
                }
            }

            /**
             * @var $orderPayment OrderPaymentCore
             */
            if (empty($orderPayment)) {
                $orderPayment = new OrderPayment();
                $orderPayment->order_reference = $order->reference;
            }

            $orderPayment->payment_method = $transaction->getData()['paymentDetails']['paymentProfileName'];

            $orderPayment->amount = $transaction->getPaidCurrencyAmount();

            if($transaction->isAuthorized()){
                $orderPayment->amount = $transaction->getCurrencyAmount();
            }

            $orderPayment->transaction_id = $transactionId;
            $orderPayment->id_currency = $order->id_currency;

            $orderPayment->save();

            # In case of banktransfer the total_paid_real isn't set, we're doing that now.
            if ($order_state == $this->statusPaid && $order->total_paid_real == 0) {
              $order->total_paid_real = $orderPayment->amount;
              $order->save();
            }

            $history = new OrderHistory();

            $history->id_order = $order->id;

            $history->changeIdOrderState($order_state, $order->id, true);
            $history->addWs();

            $message = "Updated order (" . $order->reference . ") to: " . $orderStateName;

        } else {
            if ($transaction->isPaid() || $transaction->isAuthorized() || $transaction->isBeingVerified()) {
                $amountPaid = $transaction->getPaidCurrencyAmount();
                if($transaction->isAuthorized()){
                    $amountPaid = $transaction->getCurrencyAmount();
                }

                try {
                    $profileId = $transaction->getData()['paymentDetails']['paymentOptionId'];
                    $paymentMethodName = $transaction->getData()['paymentDetails']['paymentProfileName'];

                    # Profile 613 is for testing purposes
                    if($profileId != 613) {
                      $settings = $this->getPaymentMethodSettings($profileId);

                      # Get the custom method name
                      $paymentMethodName = $settings->name;
                    }

                    $this->validateOrder((int)$transaction->getExtra1(), $order_state,
                      $amountPaid, $paymentMethodName, null, array('transaction_id' => $transactionId), null, false, $cart->secure_key);

                    /** @var OrderCore $orderId */
                    $orderId = Order::getIdByCartId($transaction->getExtra1());
                    $order = new Order($orderId);

                    $message = "Validated order (" . $order->reference . ") with status: " . $orderStateName;
                } catch (Exception $ex) {
                    $message = "Could not validate order, error: " . $ex->getMessage();
                    Throw new Exception($message);
                }

            }
        }

        return $transaction;
    }

    public function getTransaction($transactionId)
    {
        $this->sdkLogin();

        $transaction = \Paynl\Transaction::get($transactionId);

        return $transaction;
    }

    /**
     * @param Cart $cart
     * @param $payment_option_id
     * @param array $extra_data
     *
     * @return string
     */
    public function startPayment(Cart $cart, $payment_option_id, $extra_data = array())
    {
        $this->sdkLogin();

        $currency = new Currency($cart->id_currency);
        /** @var CurrencyCore $currency */

        $objPaymentMethod = $this->getPaymentMethod($payment_option_id);
        // make sure no fee is in the cart
        $cart->deleteProduct(Configuration::get('PAYNL_FEE_PRODUCT_ID'),0);
        $cartTotal = $cart->getOrderTotal(true, Cart::BOTH, null, null, false);
        $iPaymentFee = $this->getPaymentFee($objPaymentMethod, $cartTotal);
        $this->addPaymentFee($cart, $iPaymentFee);

        $products = $this->_getProductData($cart);

        $description = $cart->id;

        if (Configuration::get('PAYNL_DESCRIPTION_PREFIX')) {
            $description = Configuration::get('PAYNL_DESCRIPTION_PREFIX') . $description;
        }

        $startData = array(
            'amount' => $cart->getOrderTotal(true, Cart::BOTH, null, null, false),
            'currency' => $currency->iso_code,
            'returnUrl' => $this->context->link->getModuleLink($this->name, 'finish', array(), true),
            'exchangeUrl' => $this->context->link->getModuleLink($this->name, 'exchange', array(), true),
            'paymentMethod' => $payment_option_id,
            'description' => $description,
            'testmode' => Configuration::get('PAYNL_TEST_MODE'),
            'extra1' => $cart->id,
            'products' => $products
        );

        $addressData = $this->_getAddressData($cart);
        $startData = array_merge($startData, $addressData);

        if (isset($extra_data['bank'])) {
            $startData['bank'] = $extra_data['bank'];
        }

        # Retrieve language
        $startData['language'] = $this->getLanguageForOrder($cart);

        $result = \Paynl\Transaction::start($startData);

      if ($this->shouldValidateOnStart($payment_option_id)) {
        // flush the package list, so the fee is added to it.
        $this->context->cart->getPackageList(true);

        $paymentMethodSettings = $this->getPaymentMethodSettings($payment_option_id);

        $this->validateOrder($cart->id, $this->statusPending, 0, $paymentMethodSettings->name,
            null, array(), null, false, $cart->secure_key);
      }

        return $result->getRedirectUrl();
    }

  /**
   * Retrieve the settings of a specific payment with payment_profile_id
   *
   * @param $payment_profile_id
   * @return bool
   */
  private function getPaymentMethodSettings($payment_profile_id)
  {
    $paymentMethods = json_decode(Configuration::get('PAYNL_PAYMENTMETHODS'));
    foreach ($paymentMethods as $objPaymentSettings) {
      if ($objPaymentSettings->id == $payment_profile_id) {
        return $objPaymentSettings;
      }
    }
    return false;
  }


  private function getPaymentMethod($payment_option_id)
    {
        foreach ($this->getPaymentMethodsForCart() as $objPaymentOption) {
            if ($objPaymentOption->id == (int)$payment_option_id) {
                return $objPaymentOption;
            }
        }

        return null;
    }

    /**
     * @param Cart $cart
     * @param $iFee_wt
     */
    private function addPaymentFee(Cart $cart, $iFee_wt)
    {
        if ($iFee_wt <= 0) {
            return;
        }
        $this->createPaymentFeeProduct();
        $feeProduct = new Product(Configuration::get('PAYNL_FEE_PRODUCT_ID'), true);

        $cart->updateQty(1, Configuration::get('PAYNL_FEE_PRODUCT_ID'));

        $cart->save();

        $vatRate = $feeProduct->tax_rate;
        // if product doesn't exists, it assumes to have a taxrate 0
        if($vatRate == 0) {
            foreach($cart->getProducts() as $product) {
                if($vatRate < $product['rate']) {
                    $vatRate = $product['rate'];
                }
            }
        }

        $iFee_wt = (float)number_format($iFee_wt, 2);
        $iFee = (float)number_format((float)$iFee_wt / (1 + ($vatRate / 100)), 2);

        $specific_price = new SpecificPrice();
        $specific_price->id_product = (int)$feeProduct->id; // choosen product id
        $specific_price->id_product_attribute = $feeProduct->getDefaultAttribute($feeProduct->id);
        $specific_price->id_cart = (int)$cart->id;
        $specific_price->id_shop = (int)$this->context->shop->id;
        $specific_price->id_currency = 0;
        $specific_price->id_country = 0;
        $specific_price->id_group = 0;
        $specific_price->id_customer = 0;
        $specific_price->from_quantity = 1;
        $specific_price->price = (float)$iFee;
        $specific_price->reduction_type = 'amount';
        $specific_price->reduction_tax = 1;
        $specific_price->reduction = 0;
        $specific_price->from = date("Y-m-d H:i:s", strtotime('-1 day'));
        $specific_price->to = date("Y-m-d H:i:s", strtotime('+1 week'));

        $specific_price->add();
    }

    /**
     * @param Cart $cart
     *
     * @return array
     */
    private function _getProductData(Cart $cart)
    {
        $arrResult = array();
        foreach ($cart->getProducts(true) as $product) {
            $arrResult[] = array(
                'id' => $product['id_product'],
                'name' => $product['name'],
                'price' => $product['price_wt'],
                'vatPercentage' => $product['rate'],
                'qty' => $product['cart_quantity']
            );
        }
        $shippingCost_wt = $cart->getTotalShippingCost();
        $shippingCost = $cart->getTotalShippingCost(null, false);
        $arrResult[] = array(
            'id' => 'shipping',
            'name' => $this->l('Shipping costs'),
            'price' => $shippingCost_wt,
            'tax' => $shippingCost_wt - $shippingCost,
            'qty' => 1,
        );

        return $arrResult;
    }

    /**
     * @param Cart $cart
     *
     * @return array
     */
    private function _getAddressData(Cart $cart)
    {
        /** @var CartCore $cart */
        $shippingAddressId = $cart->id_address_delivery;
        $invoiceAddressId = $cart->id_address_invoice;
        $customerId = $cart->id_customer;
        $objShippingAddress = new Address($shippingAddressId);
        $objInvoiceAddress = new Address($invoiceAddressId);
        $customer = new Customer($customerId);
        /** @var AddressCore $objShippingAddress */
        /** @var AddressCore $objInvoiceAddress */
        /** @var CustomerCore $customer */
        $enduser = array();
        $enduser['initials'] = substr($objShippingAddress->firstname, 0, 1);
        $enduser['lastName'] = $objShippingAddress->lastname;
        $enduser['birthDate'] = $customer->birthday;
        $enduser['phoneNumber'] = $objShippingAddress->phone ? $objShippingAddress->phone : $objShippingAddress->phone_mobile;
        $enduser['emailAddress'] = $customer->email;

        list($shipStreet, $shipHousenr) = Paynl\Helper::splitAddress(trim($objShippingAddress->address1 . ' ' . $objShippingAddress->address2));
        list($invoiceStreet, $invoiceHousenr) = Paynl\Helper::splitAddress(trim($objInvoiceAddress->address1 . ' ' . $objInvoiceAddress->address2));

        /** @var CountryCore $shipCountry */
        $shipCountry = new Country($objShippingAddress->id_country);
        $address = array(
            'streetName' => @$shipStreet,
            'houseNumber' => @$shipHousenr,
            'zipCode' => $objShippingAddress->postcode,
            'city' => $objShippingAddress->city,
            'country' => $shipCountry->iso_code
        );

        /** @var CountryCore $invoiceCountry */
        $invoiceCountry = new Country($objInvoiceAddress->id_country);
        $invoiceAddress = array(
            'initials' => substr($objInvoiceAddress->firstname, 0, 1),
            'lastName' => $objInvoiceAddress->lastname,
            'streetName' => @$invoiceStreet,
            'houseNumber' => @$invoiceHousenr,
            'zipCode' => $objInvoiceAddress->postcode,
            'city' => $objInvoiceAddress->city,
            'country' => $invoiceCountry->iso_code
        );

        return array(
            'enduser' => $enduser,
            'address' => $address,
            'invoiceAddress' => $invoiceAddress
        );
    }

    /**
     * Retrieve language
     *
     * @param $cart
     * @return mixed|string
     */
    private function getLanguageForOrder($cart)
    {
      $languageSetting = Tools::getValue('PAYNL_LANGUAGE', Configuration::get('PAYNL_LANGUAGE'));
      if ($languageSetting == 'auto') {
        return $this->getBrowserLanguage();
      } elseif ($languageSetting == 'cart') {
        return Language::getIsoById($cart->id_lang);
      } else {
        return $languageSetting;
      }
    }

    private function getBrowserLanguage()
    {
        if (isset($_SERVER["HTTP_ACCEPT_LANGUAGE"])) {
            return $this->parseDefaultLanguage($_SERVER["HTTP_ACCEPT_LANGUAGE"]);
        } else {
            return $this->parseDefaultLanguage(null);
        }
    }

    private function parseDefaultLanguage($http_accept, $deflang = "en")
    {
        if (isset($http_accept) && strlen($http_accept) > 1) {
            $lang = array();
            # Split possible languages into array
            $x = explode(",", $http_accept);
            foreach ($x as $val) {
                #check for q-value and create associative array. No q-value means 1 by rule
                if (preg_match("/(.*);q=([0-1]{0,1}.[0-9]{0,4})/i", $val,
                    $matches)) {
                    $lang[$matches[1]] = (float)$matches[2] . '';
                } else {
                    $lang[$val] = 1.0;
                }
            }

            $arrLanguages = $this->getLanguages();
            $arrAvailableLanguages = array();
            foreach ($arrLanguages as $language) {
                if ($language['language_id'] != 'auto') {
                    $arrAvailableLanguages[] = $language['language_id'];
                }
            }

            #return default language (highest q-value)
            $qval = 0.0;
            foreach ($lang as $key => $value) {
                $languagecode = strtolower(substr($key, 0, 2));

                if (in_array($languagecode, $arrAvailableLanguages)) {
                    if ($value > $qval) {
                        $qval = (float)$value;
                        $deflang = $key;
                    }
                }
            }
        }

        return strtolower(substr($deflang, 0, 2));
    }

    public function getLanguages()
    {
        return array(
            array(
                'language_id' => 'nl',
                'label' => $this->l('Dutch')
            ),
            array(
                'language_id' => 'en',
                'label' => $this->l('English')
            ),
            array(
                'language_id' => 'es',
                'label' => $this->l('Spanish')
            ),
            array(
                'language_id' => 'it',
                'label' => $this->l('Italian')
            ),
            array(
                'language_id' => 'fr',
                'label' => $this->l('French')
            ),
            array(
                'language_id' => 'de',
                'label' => $this->l('German')
            ),
            array(
                'language_id' => 'cart',
                'label' => $this->l('Webshop language')
            ),
            array(
                'language_id' => 'auto',
                'label' => $this->l('Automatic (Browser language)')
            ),
        );
    }

    /**
     * @param $payment_option_id
     *
     * @return bool
     */
    public function shouldValidateOnStart($payment_option_id)
    {
        if ($payment_option_id == 136) {
            return true;
        }

        return false;
    }

    /**
     * @param $payment_option_id
     *
     * @return string
     */
    private function getPaymentMethodName($payment_option_id)
    {
        $this->sdkLogin();

        $payment_methods = \Paynl\Paymentmethods::getList();
        if (isset($payment_methods[$payment_option_id])) {
            return $payment_methods[$payment_option_id]['name'];
        } else {
            return "Unknown";
        }
    }

    public function getContent()
    {

        if (Tools::isSubmit('btnSubmit')) {
            $this->_postValidation();
            if (!count($this->_postErrors)) {
                $this->_postProcess();
            } else {
                foreach ($this->_postErrors as $err) {
                    $this->_html .= $this->displayError($err);
                }
            }
        } else {
            $this->_html .= '<br />';
        }
        $loggedin = false;
        if (!class_exists('\Paynl\Paymentmethods')) {
            $this->adminDisplayWarning($this->l('Cannot find Pay.nl SDK, did you install the source code instead of the package?'));

            return false;
        }
        try {
            $this->sdkLogin();
            //call api to check if the credentials are correct
            \Paynl\Paymentmethods::getList();
            $loggedin = true;
        } catch (\Exception  $e) {

        }

        $this->_html .= $this->renderAccountSettingsForm();
        if ($loggedin) {
            $this->_html .= $this->renderPaymentMethodsForm();
        }

        return $this->_html;
    }

    /**
     *
     */
    protected function _postValidation()
    {
        if (Tools::isSubmit('btnSubmit')) {
            if (!Tools::getValue('PAYNL_API_TOKEN')) {
                $this->_postErrors[] = $this->l('APItoken is required');
            } elseif (!Tools::getValue('PAYNL_SERVICE_ID')) {
                $this->_postErrors[] = $this->l('ServiceId is required');
            }

            if (empty($this->_postErrors)) {
                // check if apitoken and serviceId are valid
                $this->sdkLogin();

                try {
                    Paynl\Paymentmethods::getList();
                } catch (\Paynl\Error\Error $e) {
                    $this->_postErrors[] = $e->getMessage();
                }
            }
        }
    }

    protected function _postProcess()
    {
        if (Tools::isSubmit('btnSubmit')) {
            Configuration::updateValue('PAYNL_API_TOKEN', Tools::getValue('PAYNL_API_TOKEN'));
            Configuration::updateValue('PAYNL_SERVICE_ID', Tools::getValue('PAYNL_SERVICE_ID'));
            Configuration::updateValue('PAYNL_TEST_MODE', Tools::getValue('PAYNL_TEST_MODE'));
            Configuration::updateValue('PAYNL_VALIDATION_DELAY', Tools::getValue('PAYNL_VALIDATION_DELAY'));
            Configuration::updateValue('PAYNL_DESCRIPTION_PREFIX', Tools::getValue('PAYNL_DESCRIPTION_PREFIX'));
            Configuration::updateValue('PAYNL_PAYMENTMETHODS', Tools::getValue('PAYNL_PAYMENTMETHODS'));
            Configuration::updateValue('PAYNL_LANGUAGE', Tools::getValue('PAYNL_LANGUAGE'));
        }
        $this->_html .= $this->displayConfirmation($this->l('Settings updated'));
    }

    public function renderAccountSettingsForm()
    {
        $fields_form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Pay.nl Account Settings. Plugin version 4.2.6'),
                    'icon' => 'icon-envelope'
                ),
                'input' => array(
                    array(
                        'type' => 'text',
                        'label' => $this->l('APIToken'),
                        'name' => 'PAYNL_API_TOKEN',
                        'desc' => $this->l('You can find your API token at the bottom of https://admin.pay.nl/my_merchant'),
                        'required' => true
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('ServiceId'),
                        'name' => 'PAYNL_SERVICE_ID',
                        'desc' => $this->l('The SL-code of your service on https://admin.pay.nl/programs/programs'),
                        'required' => true
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Transaction description prefix'),
                        'name' => 'PAYNL_DESCRIPTION_PREFIX',
                        'desc' => $this->l('A prefix added to the transaction description'),
                        'required' => false
                    ),
                  array(
                    'type' => 'switch',
                    'label' => $this->l('Validation delay'),
                    'name' => 'PAYNL_VALIDATION_DELAY',
                    'desc' => $this->l('When payment is done, wait for Pay.nl to validate payment before redirecting to success page'),
                    'values' => array(
                      array(
                        'id' => 'validation_delay_on',
                        'value' => 1,
                        'label' => $this->l('Enabled')
                      ),
                      array(
                        'id' => 'validation_delay_off',
                        'value' => 0,
                        'label' => $this->l('Disabled')
                      )
                    ),
                  ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Test mode'),
                        'name' => 'PAYNL_TEST_MODE',
                        'desc' => $this->l('Start transactions in sandbox mode for testing.'),
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->l('Enabled')
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->l('Disabled')
                            )
                        ),
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->l('Payment screen language'),
                        'name' => 'PAYNL_LANGUAGE',
                        'desc' => $this->l("Select the language to show the payment screen in, automatic uses the browser preference"),
                        'options' => array(
                            'query' => $this->getLanguages(),
                            'id' => 'language_id',
                            'name' => 'label'
                        )
                    ),
                    array(
                        'type' => 'hidden',
                        'name' => 'PAYNL_PAYMENTMETHODS',
                    )
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                )
            ),
        );

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $lang = new Language((int)Configuration::get('PS_LANG_DEFAULT'));
        $helper->default_form_language = $lang->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $this->fields_form = array();
        $helper->id = (int)Tools::getValue('id_carrier');
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'btnSubmit';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules',
                false) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFieldsValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id
        );

        return $helper->generateForm(array($fields_form));
    }

    /**
     * @return array
     */
    public function getConfigFieldsValues()
    {
        $paymentMethods = Tools::getValue('PAYNL_PAYMENTMETHODS', '[]');

        if ($paymentMethods == '[]') {
            $paymentMethods = $this->getPaymentMethodsCombined();
            $paymentMethods = json_encode($paymentMethods);
        }

        return array(
            'PAYNL_API_TOKEN' => Tools::getValue('PAYNL_API_TOKEN', Configuration::get('PAYNL_API_TOKEN')),
            'PAYNL_SERVICE_ID' => Tools::getValue('PAYNL_SERVICE_ID', Configuration::get('PAYNL_SERVICE_ID')),
            'PAYNL_TEST_MODE' => Tools::getValue('PAYNL_TEST_MODE', Configuration::get('PAYNL_TEST_MODE')),
            'PAYNL_VALIDATION_DELAY' => Tools::getValue('PAYNL_VALIDATION_DELAY', Configuration::get('PAYNL_VALIDATION_DELAY')),
            'PAYNL_DESCRIPTION_PREFIX' => Tools::getValue('PAYNL_DESCRIPTION_PREFIX', Configuration::get('PAYNL_DESCRIPTION_PREFIX')),
            'PAYNL_LANGUAGE' => Tools::getValue('PAYNL_LANGUAGE', Configuration::get('PAYNL_LANGUAGE')),
            'PAYNL_PAYMENTMETHODS' => $paymentMethods
        );
    }

    /**
     * @return array
     */
    private function getPaymentMethodsCombined()
    {
        $resultArray = array();
        $savedPaymentMethods = json_decode(Configuration::get('PAYNL_PAYMENTMETHODS'));
        try {
            $this->sdkLogin();
            $paymentmethods = \Paynl\Paymentmethods::getList();
            $paymentmethods = (array)$paymentmethods;
            foreach ($savedPaymentMethods as $paymentmethod) {
                if (isset($paymentmethods[$paymentmethod->id])) {
                    $resultArray[] = $paymentmethod;
                    unset($paymentmethods[$paymentmethod->id]);
                }
            }
            foreach ($paymentmethods as $paymentmethod) {
                $resultArray[] = array(
                    'id' => $paymentmethod['id'],
                    'name' => $paymentmethod['name'],
                    'enabled' => false,
                );
            }
        } catch (\Exception  $e) {

        }

        return $resultArray;
    }

    /**
     * @return string
     */
    public function renderPaymentMethodsForm()
    {

        $this->context->controller->addJs($this->_path . 'views/js/jquery-ui/jquery-ui.js');
        $this->context->controller->addJs($this->_path . 'views/js/angular/angular.js');

        $this->context->controller->addJs($this->_path . 'views/js/angular-ui-sortable/sortable.js');
        $this->context->controller->addJs($this->_path . 'views/js/angular-ui-switch/angular-ui-switch.js');

        $this->context->controller->addCss($this->_path . 'views/js/angular-ui-switch/angular-ui-switch.css');
        $this->context->controller->addCss($this->_path . 'css/admin.css');

        $this->smarty->assign(array(
            'available_countries' => $this->getCountries(),
            'available_carriers' => $this->getCarriers()
        ));

        return $this->display(__FILE__, 'admin_paymentmethods.tpl');
    }

    public function getCarriers()
    {
        return Carrier::getCarriers($this->context->language->id, true);
    }

    public function getCountries()
    {
        return Country::getCountries($this->context->language->id, true);
    }
}
