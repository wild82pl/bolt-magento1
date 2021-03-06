<?php
/**
 * Bolt magento plugin
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * @category   Bolt
 * @package    Bolt_Boltpay
 * @copyright  Copyright (c) 2016-2020 Bolt Financial, Inc (https://www.bolt.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

/**
 * Class Bolt_Boltpay_Model_Observer
 *
 * This class implements order event behavior
 */
class Bolt_Boltpay_Model_Observer
{
    use Bolt_Boltpay_BoltGlobalTrait;

    /**
     * @var Bolt_Boltpay_Model_Order  Model used for utility functions concerning Bolt orders
     */
    public $orderModel;

    /**
     * Creates reusable objects for the class
     */
    function __construct()
    {
        $this->orderModel = Mage::getSingleton('boltpay/order');
    }

    /**
     * Initializes the benchmark profiler
     *
     * event: controller_front_init_before
     */
    public function initializeBenchmarkProfiler()
    {
        $hasInitializedProfiler = Mage::registry('initializedBenchmark');

        if (!$hasInitializedProfiler) {
            Mage::register('bolt/request_start_time', microtime(true), true);

            /**
             * Logs the time taken to reach the point in the codebase
             *
             * @param string $label                  Label to add to the benchmark
             * @param bool   $shouldLogIndividually  If true, the benchmark will be logged separately in addition to with the full log
             * @param bool   $shouldIncludeInFullLog If false, this benchmark will not be included in the full log
             * @param bool   $shouldFlushFullLog     If true, will log the full log up to this benchmark call.
             */
            function benchmark( $label, $shouldLogIndividually = false, $shouldIncludeInFullLog = true, $shouldFlushFullLog = false ) {
                /** @var Bolt_Boltpay_Helper_Data $boltHelper */
                $boltHelper = Mage::helper('boltpay');
                $boltHelper->logBenchmark($label, $shouldLogIndividually, $shouldIncludeInFullLog, $shouldFlushFullLog);
            }

            Mage::register('initializedBenchmark', true, true);
        }
    }

    /**
     * Update Feature Switches if necessary
     *
     * event: controller_front_init_before
     */
    public function updateFeatureSwitches()
    {
        if (Bolt_Boltpay_Model_FeatureSwitch::$shouldUpdateFeatureSwitches) {
            try {
                Mage::getSingleton("boltpay/featureSwitch")->updateFeatureSwitches();
            } catch (\GuzzleHttp\Exception\GuzzleException $guzzleException) {
                // We are treating failure to update feature switches as
                // a non-fatal exception.  In other words, if the feature
                // switches fail to update on an upgrade, we do not block Magento
                // from functioning
                $this->boltHelper()->logException($guzzleException);
                $this->boltHelper()->notifyException($guzzleException, array(), 'error');
            }
        }
    }

    /**
     * Submits the final benchmark profiler log
     *
     * event: controller_front_send_response_after
     */
    public function logFullBenchmarkProfile()
    {
        benchmark(null, false, false, true);
    }

    /**
     * Clears the Shopping Cart except product page checkout order after the success page
     *
     * event: checkout_onepage_controller_success_action
     */
    public function clearShoppingCartExceptPPCOrder()
    {
        if (Mage::app()->getRequest()->getParam('checkoutType') != Bolt_Boltpay_Block_Checkout_Boltpay::CHECKOUT_TYPE_PRODUCT_PAGE) {
            Mage::helper('checkout/cart')->getCart()->truncate()->save();
        }
    }

    /**
     * This will clear the cart cache, forcing creation of a new immutable quote, if
     * the parent quote has been flagged by having a parent quote Id as its own
     * id.
     *
     * event: controller_action_predispatch
     *
     * @param Varien_Event_Observer $observer event contains front (Mage_Core_Controller_Varien_Front)
     */
    public function clearCartCacheOnOrderCanceled($observer) {

        /** @var Mage_Sales_Model_Quote $quote */
        $quote = Mage::getSingleton('checkout/session')->getQuote();

        if ($quote && is_int($quote->getParentQuoteId()) && $quote->getIsActive()) {
            Mage::getSingleton('core/session')->unsCachedCartData();
            // clear the parent quote ID to re-enable cart cache
            $quote->setParentQuoteId(null)->save();
        }
    }

    /**
     * Sets native session variables for the order success method that were made available via params sent by
     * Bolt.  If this is not an order success call invoked by Bolt, then the function is exited.
     *
     * event: controller_action_predispatch
     *
     * @param Varien_Event_Observer $observer unused
     *
     * @throws Exception when quote totals have not been properly collected
     */
    public function setSuccessSessionData($observer) {

        /** @var Mage_Checkout_Model_Session $checkoutSession */
        $checkoutSession = Mage::getSingleton('checkout/session');
        $requestParams = Mage::app()->getRequest()->getParams();

        // Handle only Bolt orders
        if (!isset($requestParams['bolt_payload'])  && !isset($requestParams['bolt_transaction_reference'])) {
            return;
        }

        if (isset($requestParams['bolt_payload'])) {
            $payload = @$requestParams['bolt_payload'];
            $signature = base64_decode(@$requestParams['bolt_signature']);

            if (!$this->boltHelper()->verify_hook($payload, $signature)) {
                // If signature verification fails, we log the error and immediately return control to Magento
                $exception = new Bolt_Boltpay_OrderCreationException(
                    Bolt_Boltpay_OrderCreationException::E_BOLT_GENERAL_ERROR,
                    Bolt_Boltpay_OrderCreationException::E_BOLT_GENERAL_ERROR_TMPL_HMAC
                );
                $this->boltHelper()->notifyException($exception, array(), 'warning');
                $this->boltHelper()->logWarning($exception->getMessage());

                return;
            }

            $quote = $checkoutSession->getQuote();

            /* @var Mage_Sales_Model_Quote $immutableQuote */
            $immutableQuote = Mage::getModel('sales/quote')->loadByIdWithoutStore($quote->getParentQuoteId());
        } else if (isset($requestParams['bolt_transaction_reference'])) {
            ////////////////////////////////////////////////////////////////////
            // Orphaned transaction and v 1.x (legacy) Success page handling
            // Note: order may not have been created at this point so in those
            // cases, we use a psuedo order id to meet success page rendering
            // requirements
            ////////////////////////////////////////////////////////////////////

            /** @var Bolt_Boltpay_Model_Order $orderModel */
            $orderModel = Mage::getModel('boltpay/order');
            $transaction = $this->boltHelper()->fetchTransaction($requestParams['bolt_transaction_reference']);

            $immutableQuoteId = $this->boltHelper()->getImmutableQuoteIdFromTransaction($transaction);
            $immutableQuote = $orderModel->getQuoteById($immutableQuoteId);
            $incrementId = $this->boltHelper()->getIncrementIdFromTransaction($transaction);

            /** @var Mage_Sales_Model_Order $order */
            $order = Mage::getModel('sales/order')->loadByIncrementId($incrementId);
            $orderEntityId = (!$order->isObjectNew()) ? $order->getId() : Bolt_Boltpay_Model_Order::MAX_ORDER_ID;  # use required max sentinel id if order not yet created

            $requestParams['lastQuoteId'] = $requestParams['lastSuccessQuoteId'] = $immutableQuoteId;
            $requestParams['lastOrderId'] = $orderEntityId;
            $requestParams['lastRealOrderId'] = $incrementId;
        }

        $checkoutSession
            ->clearHelperData();

        $recurringPaymentProfilesIds = array();
        $recurringPaymentProfiles = $immutableQuote->collectTotals()->prepareRecurringPaymentProfiles();

        /** @var Mage_Payment_Model_Recurring_Profile $profile */
        foreach((array)$recurringPaymentProfiles as $profile) {
            $recurringPaymentProfilesIds[] = $profile->getId();
        }

        $checkoutSession
            ->setLastQuoteId($requestParams['lastQuoteId'])
            ->setLastSuccessQuoteId($requestParams['lastSuccessQuoteId'])
            ->setLastOrderId($requestParams['lastOrderId'])
            ->setLastRealOrderId($requestParams['lastRealOrderId'])
            ->setLastRecurringProfileIds($recurringPaymentProfilesIds);

    }

    /**
     * Event handler called when bolt payment capture.
     * Add the message Magento Order Id: "xxxxxxxxx" to the standard payment capture message.
     *
     * event: sales_order_payment_capture
     *
     * @param Varien_Event_Observer $observer Observer event contains payment object
     */
    public function addMessageWhenCapture($observer)
    {
        /** @var Mage_Sales_Model_Order_Payment $payment */
        $payment = $observer->getEvent()->getPayment();
        $order = $payment->getOrder();
        $method = $payment->getMethod();
        $message = '';

        if (strtolower($method) == Bolt_Boltpay_Model_Payment::METHOD_CODE) {
            $message .= ($incrementId = $order->getIncrementId()) ? $this->boltHelper()->__('Magento Order ID: "%s".', $incrementId) : "";
            if (!empty($message)) {
                $observer->getEvent()->getPayment()->setPreparedMessage($message);
            }
        }
    }

    /**
     * Hides the Bolt Pre-auth order states from the admin->Sales->Order list
     *
     * event: sales_order_grid_collection_load_before
     *
     * @param Varien_Event_Observer $observer Observer event contains an orderGridCollection object
     */
    public function hidePreAuthOrders($observer) {
        if ($this->boltHelper()->getExtraConfig('displayPreAuthOrders')) { return; }

        /** @var Mage_Sales_Model_Resource_Order_Grid_Collection $orderGridCollection */
        $orderGridCollection = $observer->getEvent()->getOrderGridCollection();
        $orderGridCollection->addFieldToFilter(
            'main_table.status',
            array(
                'nin'=>array(
                    Bolt_Boltpay_Model_Payment::TRANSACTION_PRE_AUTH_PENDING,
                    Bolt_Boltpay_Model_Payment::TRANSACTION_PRE_AUTH_CANCELED
                )
            )
        );
    }

    /**
     * Marks order as just placed to assist in preventing 3rd party plugins from
     * changing Bolt order status
     *
     * event: sales_order_place_after
     *
     * @param Varien_Event_Observer $observer Observer event contains an order object
     */
    public function markThatBoltOrderWasJustPlaced($observer) {
        /** @var Mage_Sales_Model_Order $order */
        $order = $observer->getEvent()->getOrder();

        if (!$this->orderModel->isBoltOrder($order)) { return; }

        Bolt_Boltpay_Helper_Data::$boltOrderWasJustPlaced = true;
    }

    /**
     * Prevents Magento from changing the Bolt preauth statuses
     *
     * event: sales_order_save_before
     *
     * @param Varien_Event_Observer $observer Observer event contains an order object
     */
    public function safeguardPreAuthStatus($observer) {
        /** @var Mage_Sales_Model_Order $order */
        $order = $observer->getEvent()->getOrder();

        if (!$this->orderModel->isBoltOrder($order)) { return; }

        ////////////////////////////////////////////////////////////////////////////////////
        // Load the old data directly from the DB in case a model is used without loading
        // where $model->getOrigData() will not be populated
        ////////////////////////////////////////////////////////////////////////////////////
        /** @var Mage_Sales_Model_Order $orderInOriginalState */
        $orderInOriginalState = Mage::getModel('Mage_Sales_Model_Order')->load($order->getId());
        $originalOrderStatus = $orderInOriginalState->getStatus();
        $originalOrderState = $orderInOriginalState->getState();
        ////////////////////////////////////////////////////////////////////////////////////

        if (
            (
                !Bolt_Boltpay_Helper_Data::$fromHooks ||
                (
                    Bolt_Boltpay_Helper_Data::$boltOrderWasJustPlaced
                    && !Bolt_Boltpay_Helper_Data::$canChangePreAuthStatus
                )
            )
            && in_array(
                $originalOrderStatus,
                array(
                    Bolt_Boltpay_Model_Payment::TRANSACTION_PRE_AUTH_PENDING,
                    Bolt_Boltpay_Model_Payment::TRANSACTION_PRE_AUTH_CANCELED
                )
            )
        )
        {
            $order->setState($originalOrderState, $originalOrderStatus);
        }

        if (Bolt_Boltpay_Helper_Data::$boltOrderWasJustPlaced) {
            # prevent any further changing of status by observers on initial save
            Bolt_Boltpay_Helper_Data::$canChangePreAuthStatus = false;
        }
    }

    /**
     * This is the last chance, bottom line price check.  It is done after the submit service
     * has created the order, but before the order is committed to the database.  This allows
     * to get the actual totals that will be stored in the database and catch all unexpected
     * changes.  We have the option to attempt to correct any problems here.  If there remain
     * any unhandled problems, we can throw an exception and avoid complex order rollback.
     *
     * This is called from the observer context
     *
     * event: sales_model_service_quote_submit_before
     *
     * @param Varien_Event_Observer $observer Observer event contains an order and (immutable) quote
     *                                        -  Mage_Sales_Model_Order order
     *                                        -  Mage_Sales_Model_Quote quote
     *
     *                                        The $quote, in turn holds
     *                                        -  Mage_Sales_Model_Quote parent (ONLY pre-auth; will be empty for admin)
     *                                        -  object (bolt) transaction (ONLY pre-auth; will be empty for admin)
     *
     *
     * @throws Exception    if an unknown error occurs
     * @throws Bolt_Boltpay_OrderCreationException if the bottom line price total differs by allowed tolerance
     *
     */
    public function validateBeforeOrderCommit($observer) {
        /** @var  Bolt_Boltpay_Model_Order $orderModel */
        $orderModel = Mage::getModel('boltpay/order');
        $orderModel->validateBeforeOrderCommit($observer);
    }

    /**
     * Automatically create an invoice after creating a shipment
     *
     * event: sales_order_shipment_save_after
     *
     * @param Varien_Event_Observer $observer Observer event contains a shipment object
     */
    public function createInvoiceAfterCreatingShipment($observer)
    {
        $shipment = $observer->getEvent()->getShipment();
        $order = $shipment->getOrder();
        if (
            !$this->boltHelper()->getAutoCreateInvoiceAfterCreatingShipment()
            || !Mage::getModel('boltpay/order')->isBoltOrder($order)
        ) {
            return;
        }

        try {
            if ($order->canInvoice()) {
                $invoiceItems = $this->getInvoiceItemsFromShipment($shipment);
                if (count($invoiceItems) > 0) {
                    $invoice = Mage::getModel('sales/service_order', $order)->prepareInvoice($invoiceItems);
                    $invoice->setRequestedCaptureCase(Mage_Sales_Model_Order_Invoice::CAPTURE_ONLINE);
                    $invoice->register();
                    $transaction = Mage::getModel('core/resource_transaction')
                        ->addObject($invoice)
                        ->addObject($order);
                    $transaction->save();
                }
            }
        } catch (\Exception $exception) {
            $this->boltHelper()->logException($exception);
            $this->boltHelper()->notifyException($exception);
        }
    }

    /**
     * @param $shipment
     * @return array
     */
    private function getInvoiceItemsFromShipment($shipment)
    {
        $invoiceItems = array();
        $shipmentItems = $shipment->getAllItems();
        foreach ($shipmentItems as $shipmentItem) {
            $qty = $this->getApplicableQtyToInvoice($shipmentItem);
            if ($qty > 0) {
                $invoiceItems[$shipmentItem->getOrderItemId()] = $qty;
            }
        }

        return $invoiceItems;
    }

    /**
     * Returns quantity to be invoiced for shipment item or false if it can't be invoiced
     *
     * @param Mage_Sales_Model_Order_Shipment_Item $shipmentItem
     * @return bool|float
     */
    private function getApplicableQtyToInvoice($shipmentItem)
    {
        $orderItem = $shipmentItem->getOrderItem();
        if ($orderItem->canInvoice()) {
            $maximumQtyToInvoice = (float)($orderItem->getQtyOrdered() - $orderItem->getQtyInvoiced());
            $shippedQty = $shipmentItem->getQty();
            return ($shippedQty > $maximumQtyToInvoice) ? $maximumQtyToInvoice : $shippedQty;
        }

        return false;
    }
}
