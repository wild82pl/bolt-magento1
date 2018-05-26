<?php
/**
 * Magento
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@magento.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade the Bolt extension
 * to a newer versions in the future. If you wish to customize this extension
 * for your needs please refer to http://www.magento.com for more information.
 *
 * @category   Bolt
 * @package    Bolt_Boltpay
 * @copyright  Copyright (c) 2018 Bolt Financial, Inc (http://www.bolt.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

/**
 * Class Bolt_Boltpay_Block_Checkout_Boltpay
 *
 * This block is used in boltpay/track.phtml and boltpay/replace.phtml templates
 *
 * This is defined in boltpay.xml config file
 *
 * The purpose is to add the Bolt tracking javascript files to every page, Bolt connect javascript to order and product pages,
 * create the order on Bolt side and set up the javascript BoltCheckout.configure process with cart and hint data.
 *
 */
class Bolt_Boltpay_Block_Checkout_Boltpay
    extends Mage_Checkout_Block_Onepage_Review_Info
{

    /**
     * @var string The Bolt sandbox url for the javascript
     */
    const JS_URL_TEST = 'https://connect-sandbox.bolt.com';

    /**
     * @var string The Bolt production url for the javascript
     */
    const JS_URL_PROD = 'https://connect.bolt.com';

    /**
     * @var int flag that represents if the capture is automatically done on authentication
     */
    const AUTO_CAPTURE_ENABLED = 1;


    const CSS_SUFFIX = 'bolt-css-suffix';

    /**
     * Set the connect javascript url to production or sandbox based on store config settings
     */
    public function _construct()
    {
        parent::_construct();
        $this->_jsUrl = Mage::getStoreConfig('payment/boltpay/test') ?
            self::JS_URL_TEST . "/connect.js":
            self::JS_URL_PROD . "/connect.js";
    }

    /**
     * Get the track javascript url, production or sandbox, based on store config settings
     */
    public function getTrackJsUrl()
    {
        return Mage::getStoreConfig('payment/boltpay/test') ?
            self::JS_URL_TEST . "/track.js":
            self::JS_URL_PROD . "/track.js";
    }

    /**
     * Creates an order on Bolt end
     *
     * @param Mage_Sales_Model_Quote $quote    Magento quote object which represents order/cart data
     * @param bool $multipage                  Is checkout type Multi-Page Checkout, the default is true, set to false for One Page Checkout
     * @return mixed json based PHP object
     */
    private function createBoltOrder($quote, $multipage)
    {

        // Load the required helper class
        $boltHelper = Mage::helper('boltpay/api');

        $items = $this->getItems();
        if (empty($items)) return json_decode('{"token" : ""}');

        // Generates order data for sending to Bolt create order API.
        $orderRequest = $boltHelper->buildOrder($quote, $items, $multipage);

        //Mage::log("order_request: ". var_export($order_request, true), null,"bolt.log");

        // Calls Bolt create order API
        return $boltHelper->transmit('orders', $orderRequest);
    }

    /**
     * Initiates the Bolt order creation / token receiving and sets up BoltCheckout with generated data.
     * In BoltCheckout.configure success callback the order is saved in additional ajax call to
     * Bolt_Boltpay_OrderController save action.
     *
     * @param bool $multipage       Is checkout type Multi-Page Checkout, the default is true, set to false for One Page Checkout
     * @return string               BoltCheckout javascript
     */
    public function getCartDataJs($multipage = true)
    {
        try {
            // Get customer and cart session objects
            $customerSession = Mage::getSingleton('customer/session');
            $session = Mage::getSingleton('checkout/session');

            /* @var Mage_Sales_Model_Quote $sessionQuote */
            $sessionQuote = $session->getQuote();

            // Load the required helper class
            $boltHelper = Mage::helper('boltpay/api');

            ///////////////////////////////////////////////////////////////
            // Populate hints data from quote or customer shipping address.
            //////////////////////////////////////////////////////////////
            $hint_data = $this->getAddressHints($customerSession, $sessionQuote);
            ///////////////////////////////////////////////////////////////

            ///////////////////////////////////////////////////////////////////////////////////////
            // Merchant scope: get "bolt_user_id" if the user is logged in or should be registered,
            // sign it and add to hints.
            ///////////////////////////////////////////////////////////////////////////////////////
            $reservedUserId = $this->getReservedUserId($sessionQuote, $customerSession);
            $signResponse = null;

            if ($reservedUserId) {
                $signRequest = array(
                    'merchant_user_id' => $reservedUserId,
                );
                $signResponse = $boltHelper->transmit('sign', $signRequest);
            }

            if ($signResponse != null) {
                $hint_data['signed_merchant_user_id'] = array(
                    "merchant_user_id" => $signResponse->merchant_user_id,
                    "signature" => $signResponse->signature,
                    "nonce" => $signResponse->nonce,
                );
            }

            ///////////////////////////////////////////////////////////////////////////////////////


            if (Mage::getStoreConfig('payment/boltpay/auto_capture') == self::AUTO_CAPTURE_ENABLED) {
                $authCapture = true;
            } else {
                $authCapture = false;
            }

            if($multipage) {
                // Resets shipping rate
                $shippingMethod = $sessionQuote->getShippingAddress()->getShippingMethod();
                $boltHelper->applyShippingRate($sessionQuote, null);
            }

            // Call Bolt create order API
            try {
                /////////////////////////////////////////////////////////////////////////////////
                // We create a copy of the quote that is immutable by the customer/frontend
                // Bolt saves this quote to the database at Magento-side order save time.
                // This assures that the quote saved to Magento matches what is stored on Bolt
                // Only shipping, tax and discounts can change, and only if the shipping, tax
                // and discount calculations change on the Magento server
                ////////////////////////////////////////////////////////////////////////////////

                /*********************************************************/
                /* Clean up resources that may have previously been saved
                /* @var Mage_Sales_Model_Quote[] $expiredQuotes */
                $expiredQuotes = Mage::getModel('sales/quote')
                    ->getCollection()
                    ->addFieldToFilter('parent_quote_id', $sessionQuote->getId());

                foreach( $expiredQuotes as $expiredQuote) {
                    $expiredQuote->delete();
                }
                /*********************************************************/

                /* @var Mage_Sales_Model_Quote $immutableQuote */
                $immutableQuote = Mage::getSingleton('sales/quote');

                try {
                    $immutableQuote->merge($sessionQuote);
                } catch ( Exception $e ) {
                    Mage::helper('boltpay/bugsnag')->notifyException($e);
                }

                if (!$multipage) {
                    // For the checkout page we want to set the
                    // billing and shipping, and shipping method at this time.
                    // For multi-page, we add the addresses during the shipping and tax hook
                    // and the chosen shipping method at order save time.
                    $immutableQuote
                        ->setBillingAddress($sessionQuote->getBillingAddress())
                        ->setShippingAddress($sessionQuote->getShippingAddress())
                        ->getShippingAddress()
                        ->setShippingMethod($sessionQuote->getShippingAddress()->getShippingMethod())
                        ->save();
                }

                /*
                 *  Attempting to reset some of the values already set by merge affects the totals passed to 
                 *  Bolt in such a way that the grand total becomes 0.  Since we do not need to reset these values
                 *  we ignore them all.
                 */
                $fieldsSetByMerge = array(
                    'coupon_code',
                    'subtotal',
                    'base_subtotal',
                    'subtotal_with_discount',
                    'base_subtotal_with_discount',
                    'grand_total',
                    'base_grand_total',
                    'auctaneapi_discounts',
                    'applied_rule_ids',
                    'items_count',
                    'items_qty',
                    'virtual_items_qty',
                    'trigger_recollect',
                    'can_apply_msrp',
                    'totals_collected_flag',
                    'global_currency_code',
                    'base_currency_code',
                    'store_currency_code',
                    'quote_currency_code',
                    'store_to_base_rate',
                    'store_to_quote_rate',
                    'base_to_global_rate',
                    'base_to_quote_rate',
                    'is_changed',
                    'created_at',
                    'updated_at',
                    'entity_id'
                );

                // Add all previously saved data that may have been added by other plugins
                foreach($sessionQuote->getData() as $key => $value ) {
                    if (!in_array($key, $fieldsSetByMerge)) {
                        $immutableQuote->setData($key, $value);
                    }
                }

                /////////////////////////////////////////////////////////////////
                // Generate new increment order id and associate it with current quote, if not already assigned
                // Save the reserved order ID to the session to check order existence at frontend order save time
                /////////////////////////////////////////////////////////////////
                $reservedOrderId = $sessionQuote->reserveOrderId()->save()->getReservedOrderId();
                Mage::getSingleton('core/session')->setReservedOrderId($reservedOrderId);

                $orderCreationResponse = $this->createBoltOrder($immutableQuote, $multipage);
                $immutableQuote
                    ->setCustomer($sessionQuote->getCustomer())
                    ->setReservedOrderId($reservedOrderId)
                    ->setStoreId($sessionQuote->getStoreId())
                    ->setParentQuoteId($sessionQuote->getId())
                    ->save();

            } catch (Exception $e) {
                Mage::helper('boltpay/bugsnag')->notifyException(new Exception($e));
                $orderCreationResponse = json_decode('{"token" : ""}');
            }

            if($multipage) {
                $boltHelper->applyShippingRate($sessionQuote, $shippingMethod);
            }

            //////////////////////////////////////////////////////////////////////////
            // Generate JSON cart and hints objects for the javascript returned below.
            //////////////////////////////////////////////////////////////////////////
            $cartData = array(
                'authcapture' => $authCapture,
                'orderToken' => $orderCreationResponse->token,
            );

            if (Mage::registry("api_error")) {
                $cartData['error'] = Mage::registry("api_error");
            }

            $jsonCart = json_encode($cartData);
            $jsonHints = '{}';
            if (sizeof($hint_data) != 0) {
                // Convert $hint_data to object, because when empty data it consists array not an object
                $jsonHints = json_encode($hint_data, JSON_FORCE_OBJECT);
            }

            //////////////////////////////////////////////////////////////////////////
            // Format the success and save order urls for the javascript returned below.
            $successUrl    = $this->getUrl(Mage::getStoreConfig('payment/boltpay/successpage'));
            $saveOrderUrl = $this->getUrl('boltpay/order/save');

            //////////////////////////////////////////////////////
            // Collect the event Javascripts
            //////////////////////////////////////////////////////
            $check = Mage::getStoreConfig('payment/boltpay/check');
            $onCheckoutStart = Mage::getStoreConfig('payment/boltpay/on_checkout_start');
            $onShippingDetailsComplete = Mage::getStoreConfig('payment/boltpay/on_shipping_details_complete');
            $onShippingOptionsComplete = Mage::getStoreConfig('payment/boltpay/on_shipping_options_complete');
            $onPaymentSubmit = Mage::getStoreConfig('payment/boltpay/on_payment_submit');
            $success = Mage::getStoreConfig('payment/boltpay/success');
            $close = Mage::getStoreConfig('payment/boltpay/close');


            //////////////////////////////////////////////////////
            // Generate and return BoltCheckout javascript.
            //////////////////////////////////////////////////////
            return ("
                var json_cart = $jsonCart;
                var quote_id = '{$immutableQuote->getId()}';
                var order_completed = false;
                
                BoltCheckout.configure(
                    json_cart,
                    $jsonHints,
                    {
                      check: function() {
                        if (!json_cart.orderToken) {
                            alert(json_cart.error);
                            return false;
                        }
                        $check
                        return true;
                      },
                      
                      onCheckoutStart: function() {
                        // This function is called after the checkout form is presented to the user.
                        $onCheckoutStart
                      },
                      
                      onShippingDetailsComplete: function() {
                        // This function is called when the user proceeds to the shipping options page.
                        // This is applicable only to multi-step checkout.
                        $onShippingDetailsComplete
                      },
                      
                      onShippingOptionsComplete: function() {
                        // This function is called when the user proceeds to the payment details page.
                        // This is applicable only to multi-step checkout.
                        $onShippingOptionsComplete
                      },
                      
                      onPaymentSubmit: function() {
                        // This function is called after the user clicks the pay button.
                        $onPaymentSubmit
                      },
                      
                      success: function(transaction, callback) {
                        new Ajax.Request(
                            '$saveOrderUrl',
                            {
                                method:'post',
                                onSuccess: 
                                    function() {
                                        $success
                                        order_completed = true;
                                        callback();  
                                    },
                                parameters: 'reference='+transaction.reference
                            }
                        );
                      },
                      
                      close: function() {
                         $close
                         if (typeof bolt_checkout_close === 'function') {
                            // used internally to set overlay in firecheckout
                            bolt_checkout_close();
                         }
                         if (order_completed) {   
                            location.href = '$successUrl';
                         }
                      }
                    }
                );"
            );

        } catch (Exception $e) {
            Mage::helper('boltpay/bugsnag')->notifyException($e);
        }
    }

    /**
     * Get address data for sending as hints.
     *
     * @param $session      Customer session
     * @return array        hints data
     */
    private function getAddressHints($session, $quote)
    {

        $hints = array();

        ///////////////////////////////////////////////////////////////
        // Check if the quote shipping address is set,
        // otherwise use customer shipping address for logged in users.
        ///////////////////////////////////////////////////////////////
        $address = $quote->getShippingAddress();

        if ($session && $session->isLoggedIn()) {
            /** @var Mage_Customer_Model_Customer $customer */
            $customer = Mage::getModel('customer/customer')->load($session->getId());
            $address = $customer->getPrimaryShippingAddress();
            $email = $customer->getEmail();
        }

        /////////////////////////////////////////////////////////////////////////
        // If address exists populate the hints array with existing address data.
        /////////////////////////////////////////////////////////////////////////
        if ($address) {
            if (@$email)                   $hints['email']        = $email;
            if (@$address->getFirstname()) $hints['firstName']    = $address->getFirstname();
            if (@$address->getLastname())  $hints['lastName']     = $address->getLastname();
            if (@$address->getStreet1())   $hints['addressLine1'] = $address->getStreet1();
            if (@$address->getStreet2())   $hints['addressLine2'] = $address->getStreet2();
            if (@$address->getCity())      $hints['city']         = $address->getCity();
            if (@$address->getRegion())    $hints['state']        = $address->getRegion();
            if (@$address->getPostcode())  $hints['zip']          = $address->getPostcode();
            if (@$address->getTelephone()) $hints['phone']        = $address->getTelephone();
            if (@$address->getCountryId()) $hints['country']      = $address->getCountryId();
        }

        return array( "prefill" => $hints );
    }

    /**
     * Gets the customer custom attribute, "bolt_user_id", if not set creates one by
     * fetching new Magento customer auto increment ID for the store.
     * Applies to logged in users or the users in the process of registration during the the checkout (checkout type is "register").
     *
     * @param $quote        Magento quote object
     * @param $session      Magento customer/session object
     * @return string|null  the ID used for the Bolt user, or null if the user is not logged in and is not on the onepage checkout page
     */
    function getReservedUserId($quote, $session)
    {

        $checkout = Mage::getSingleton('checkout/type_onepage');

        $checkoutMethod = $checkout->getCheckoutMethod();

        if ($session->isLoggedIn()) {
            $customer = Mage::getModel('customer/customer')->load($session->getId());

            if ($customer->getBoltUserId() == 0 || $customer->getBoltUserId() == null) {
                //Mage::log("Creating new user id for logged in user", null, 'bolt.log');

                $custId = Mage::getSingleton('eav/config')->getEntityType("customer")->fetchNewIncrementId($quote->getStoreId());
                $customer->setBoltUserId($custId);
                $customer->save();
            }

            //Mage::log(sprintf("Using Bolt User Id: %s", $customer->getBoltUserId()), null, 'bolt.log');
            return $customer->getBoltUserId();
        } else if ($checkoutMethod == Mage_Checkout_Model_Type_Onepage::METHOD_REGISTER) {
            //Mage::log("Creating new user id for Register checkout", null, 'bolt.log');
            $custId = Mage::getSingleton('eav/config')->getEntityType("customer")->fetchNewIncrementId($quote->getStoreId());
            $session->setBoltUserId($custId);
            return $custId;
        }
    }

    /**
     * Returns CSS_SUFFIX constant to be added to selector identifiers
     * @return string
     */
    function getCssSuffix()
    {
        return self::CSS_SUFFIX;
    }

    /**
     * Reads the Replace Buttons Style config and generates selectors CSS
     * @return string
     */
    function getSelectorsCSS()
    {

        $selectorStyles = Mage::getStoreConfig('payment/boltpay/selector_styles');

        $selectorStyles = array_map('trim', explode('||', trim($selectorStyles)));

        $selectors_css = '';

        foreach ($selectorStyles as $selector) {
            preg_match('/[^{}]+/', $selector, $selector_identifier);

            $bolt_selector  = trim($selector_identifier[0]) . "-" . self::CSS_SUFFIX;

            preg_match_all('/[^{}]+{[^{}]*}/', $selector, $matches);

            foreach ($matches as $match_array) {
                foreach ($match_array as $match) {
                    preg_match('/{[^{}]*}/', $match, $css);
                    $css = $css[0];

                    preg_match('/[^{}]+/', $match, $identifiers);

                    foreach ($identifiers as $comma_delimited) {
                        $comma_delimited = trim($comma_delimited);
                        $single_identifiers = array_map('trim', explode(',', $comma_delimited));

                        foreach ($single_identifiers as $identifier) {
                            $selectors_css .= $identifier . $bolt_selector . $css;
                            $selectors_css .= $bolt_selector . " " . $identifier . $css;
                        }
                    }
                }
            }
        }

        return $selectors_css;
    }

    /**
     * Returns Additional CSS from configuration.
     * @return string
     */
    function getAdditionalCSS()
    {
        return Mage::getStoreConfig('payment/boltpay/additional_css');
    }

    /**
     * Returns the Bolt Button Theme from configuration.
     * @return string
     */
    function getTheme()
    {
        return Mage::getStoreConfig('payment/boltpay/theme');
    }

    /**
     * Returns the Bolt Sandbox Mode configuration.
     * @return string
     */
    function isTestMode()
    {
        return Mage::getStoreConfig('payment/boltpay/test');
    }

    /**
     * Returns the Replace Button Selectors configuration.
     * @return string
     */
    function getConfigSelectors()
    {
        return json_encode(array_filter(explode(',', Mage::getStoreConfig('payment/boltpay/selectors'))));
    }

    /**
     * Returns the Skip Payment Method Step configuration.
     * @return string
     */
    function isBoltOnlyPayment()
    {
        Mage::getStoreConfig('payment/boltpay/skip_payment');
    }

    /**
     * Returns the Success Page Redirect configuration.
     * @return string
     */
    function getSuccessURL()
    {
        return $this->getUrl(Mage::getStoreConfig('payment/boltpay/successpage'));
    }

    /**
     * Returns the Bolt Save Order Url.
     * @return string
     */
    function getSaveOrderURL()
    {
        return $this->getUrl('boltpay/order/save');
    }

    /**
     * Returns the Cart Url.
     * @return string
     */
    function getCartURL()
    {
        return $this->getUrl('checkout/cart');
    }

    /**
     * Returns the One Page / Multi-Page checkout Publishable key.
     * @return string
     */
    function getPaymentKey($multipage = true)
    {
        return $multipage
            ? Mage::helper('core')->decrypt(Mage::getStoreConfig('payment/boltpay/publishable_key_multipage'))
            : Mage::helper('core')->decrypt(Mage::getStoreConfig('payment/boltpay/publishable_key_onepage'));
    }

    /**
     * Returns the Enabled Bolt configuration option value.
     * @return string
     */
    function isBoltActive()
    {
        return Mage::getStoreConfig('payment/boltpay/active');
    }

    /**
     * Gets the IP address of the requesting customer.  This is used instead of simply $_SERVER['REMOTE_ADDR'] to give more accurate IPs if a
     * proxy is being used.
     *
     * @return string  The IP address of the customer
     */
    function getIpAddress()
    {
        foreach (array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR') as $key){
            if (array_key_exists($key, $_SERVER) === true){
                foreach (explode(',', $_SERVER[$key]) as $ip){
                    $ip = trim($ip); // just to be safe

                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false){
                        return $ip;
                    }
                }
            }
        }
    }

    /**
     * Gets the estimated location of the client based on client's IP address
     * This currently uses http://freegeoip.net to obtain this data which has a
     * limit of 15000 queries per hour from the store.
     *
     * When there is a need to increase this limit, it can be downloaded and hosted
     * on Bolt to remove this limit.
     *
     * @return bool|string  JSON containing geolocation info of the client, or false if the ip could not be obtained.
     */
    function getLocationEstimate()
    {
        $locationInfo = Mage::getSingleton('core/session')->getLocationInfo();

        if (empty($locationInfo)) {
            $locationInfo = $this->url_get_contents("http://freegeoip.net/json/".$this->getIpAddress());
            Mage::getSingleton('core/session')->setLocationInfo($locationInfo);
        }

        return $locationInfo;
    }

    public function url_get_contents($url)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $output = curl_exec($ch);
        curl_close($ch);
        return $output;
    }

    /**
     * Return the current quote used in the session
     *
     * @return Mage_Sales_Model_Quote
     */
    public function getQuote()
    {
        return Mage::getSingleton('checkout/session')->getQuote();
    }

    /**
     * Get PaymentKey depending the other checkout modules.
     *
     * @return string
     */
    public function getPaymentKeyDependingTheModule()
    {
        $routeName = Mage::app()->getRequest()->getRouteName();

        // If exist 'firecheckout' route we should send false.
        $param = ($routeName === 'firecheckout') ? false : true;

        return $this->getPaymentKey($param);
    }
}