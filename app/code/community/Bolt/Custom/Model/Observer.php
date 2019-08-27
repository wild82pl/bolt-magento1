<?php
/**
 * Bolt Custom magento plugin
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * @category   Bolt
 * @package    Bolt_Custom
 * @copyright  Copyright (c) 2019 Bolt Financial, Inc (https://www.bolt.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

/**
 * Class Bolt_Custom_Model_Observer
 *
 * This class implements order event behavior
 */
class Bolt_Custom_Model_Observer
{
    /**
     * @param Varien_Event_Observer $observer
     */
    public function addDeliveryEstimateTitle(Varien_Event_Observer $observer)
    {
        /**
         * This custom script get from template:
         * app/design/frontend/enterprise/newskin/template/checkout/onepage/shipping_method/available.phtml:181
         */
        $wrapper = $observer->getValueWrapper();
        $_rate = $observer->getParameters();
        $deliveryHelper = Mage::helper('tadashi_estimateddelivery');
        // Does not need to use title. Method title should consists from method title and delivery date.
//        $methodTitle = $wrapper->getValue();
        $methodTitle = $_rate->getMethodTitle();

        $deliveryEstimateText = '';
        if ($methodTitle == 'Standard') {
            $deliveryEstimateText = ' (' . $deliveryHelper->__('3-6 Business Days') . ') ' . $deliveryHelper->deliveryMessage(6);
        }
        if ($methodTitle == 'Expedited') {
            $deliveryEstimateText = ' (' . $deliveryHelper->__('2-3 Business Days') . ') ' . $deliveryHelper->deliveryMessage(3);
        }
        if ($methodTitle == 'Overnight') {
            $deliveryEstimateText = ' (' . $deliveryHelper->__('1-2 Business Days') . ') ' . $deliveryHelper->deliveryMessage(2);
        }
        if (strstr(strtoupper($methodTitle),'PB INTERNATIONAL')) {
            $deliveryEstimateText = ' (7-10 Business Days) ' . $deliveryHelper->deliveryMessage(10);
        }

        $worldwideExpeditedText = $deliveryHelper->__('(3-6 Business Days)');
        $deliveryEstimateText .= (strstr(strtoupper($methodTitle), 'WORLDWIDE EXPEDITED')) ?
            ' ' . $worldwideExpeditedText . ' ' . $deliveryHelper->deliveryMessage(6)
            : '';

        $deliveryEstimateText .= (strstr(strtoupper($methodTitle), 'I-PARCEL') ) ?
            ' (' . $deliveryHelper->__('7-13 Business Days') . ') ' . $deliveryHelper->deliveryMessage(13)
            : '';

        $observer->getValueWrapper()->setValue($methodTitle . $deliveryEstimateText);
    }

    /**
     * @param Varien_Event_Observer $observer
     * @return $this
     * @throws Mage_Core_Exception
     * @throws Mage_Core_Model_Store_Exception
     */
    public function addCustomerBalanceAmount(Varien_Event_Observer $observer)
    {
        //Mage::helper('rewardpoints')->isEnable()
        if (!Mage::helper('enterprise_customerbalance')->isEnabled()) {
            return $this;
        }

        $wrapper = $observer->getValueWrapper();
        $parameters = $observer->getParameters();

        $discountAmount = $wrapper->getValue();

        $discountType = $parameters['discount'];
        $quote = $parameters['quote'];
        $websiteId = Mage::app()->getStore($quote->getStoreId())->getWebsiteId();
        if ($discountType === 'customerbalance' && $quote->getUseCustomerBalance() && $quote->getCustomerId()) {
            /** @var Enterprise_CustomerBalance_Model_Balance $customerBalance */
            $customerBalance = Mage::getModel('enterprise_customerbalance/balance');
            $customerBalance->setCustomerId($quote->getCustomerId());
            $customerBalance->setWebsiteId($websiteId);
            $customerBalance->loadByCustomer();
            if ($customerBalance->getAmount() > 0 && $customerBalance->getAmount() > $discountAmount) {
                // set the whole value of Store Credit for current customer.
                $observer->getValueWrapper()->setValue($customerBalance->getAmount());
            }
        }
    }

    /**
     * For Mirasvist_Rewardpoints module.
     * event: bolt_boltpay_order_creation_before
     *
     * Before we create an order and collect totals we should copy data from parent quote to
     * the immutable quote.
     *
     * @param Varien_Event_Observer $observer
     * @return $this
     */
    public function transferRewardPoints(Varien_Event_Observer $observer)
    {
        if (!Mage::helper('rewardpoints')->isEnable()) {
            return $this;
        }

        $parentQuote = $observer->getEvent()['parent_quote'];
        $parentQuoteShipAddress = $parentQuote->getShippingAddress();

        $observer->getEvent()['immutable_quote']->getShippingAddress()
            ->setData('rewardpoints_base_discount', $parentQuoteShipAddress->getRewardpointsBaseDiscount())
            ->setData('rewardpoints_discount', $parentQuoteShipAddress->getRewardpointsDiscount())
            ->setData('rewardpoints_earn', $parentQuoteShipAddress->getRewardpointsEarn())
            ->setData('rewardpoints_spent', $parentQuoteShipAddress->getRewardpointsSpent())
            ->setData('rewardpoints_base_amount', $parentQuoteShipAddress->getRewardpointsBaseAmount())
            ->setData('rewardpoints_amount', $parentQuoteShipAddress->getRewardpointsAmount())
        ;
        $observer->getEvent()['immutable_quote']
            ->setData('rewardpoints_discount', $parentQuoteShipAddress->getRewardpointsDiscount())
            ->setData('rewardpoints_base_discount', $parentQuoteShipAddress->getRewardpointsDiscount())
        ;
    }

    /**
     * event: bolt_boltpay_validate_totals_before
     *
     * The Magestore_Rewardpoints module stored almost all data in checkout session, so
     * we require manually copy data from parent quote to the immutable quote.
     *
     * @param Varien_Event_Observer $observer
     * @throws Mage_Core_Model_Store_Exception
     */
    public function updateTotalsForRewardPoints(Varien_Event_Observer $observer)
    {
        $immutableQuote = $observer->getEvent()->getQuote();

        if ($immutableQuote->getRewardpointsDiscount() > 0) {
            $rpDiscount = Mage::app()->getStore()->convertPrice($immutableQuote->getRewardpointsDiscount());

            $immutableQuote->getShippingAddress()
                ->setData('rewardpoints_base_discount', $immutableQuote->getRewardpointsDiscount())
                ->setData('rewardpoints_discount', $immutableQuote->getRewardpointsDiscount())
//                ->setData('rewardpoints_earn', $parentQuoteShipAddress->getRewardpointsEarn())
                ->setData('rewardpoints_spent', abs(round($immutableQuote->getRewardpointsDiscount() * 10)))
//                ->setData('rewardpoints_base_amount', $parentQuoteShipAddress->getRewardpointsBaseAmount())
//                ->setData('rewardpoints_amount', $parentQuoteShipAddress->getRewardpointsAmount())
            ;

            $immutableQuote->getShippingAddress()->setBaseGrandTotal($immutableQuote->getShippingAddress()->getBaseGrandTotal() - $rpDiscount);
            $immutableQuote->getShippingAddress()->setGrandTotal($immutableQuote->getShippingAddress()->getGrandTotal() - $rpDiscount);

            $immutableQuote->setData('rewardpoints_discount', $immutableQuote->getRewardpointsDiscount());
            $immutableQuote->setData('rewardpoints_base_discount', $immutableQuote->getRewardpointsDiscount());
            $immutableQuote->setBaseGrandTotal($immutableQuote->getBaseGrandTotal() - $rpDiscount);
            $immutableQuote->setGrandTotal($immutableQuote->getGrandTotal() - $rpDiscount);

            $immutableQuote->save();
        }
    }
}
