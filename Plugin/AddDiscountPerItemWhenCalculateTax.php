<?php
/**
 * Created by mr.vjcspy@gmail.com - khoild@smartosc.com.
 * Date: 29/12/2016
 * Time: 11:44
 */

namespace SM\DiscountPerItem\Plugin;

use Magento\Quote\Model\Quote\Item\AbstractItem;
use Magento\Tax\Api\Data\QuoteDetailsItemInterfaceFactory;
use Magento\Tax\Model\Config;
use Magento\Tax\Model\Sales\Total\Quote\Tax;

/**
 * Class AddDiscountPerItemWhenCalculateTax
 *
 * @package SM\DiscountPerItem\Plugin
 */
class AddDiscountPerItemWhenCalculateTax
{

    /**
     * @var \Magento\Tax\Model\Config
     */
    protected $taxConfig;

    /**
     * AddDiscountPerItemWhenCalculateTax constructor.
     *
     * @param \Magento\Tax\Model\Config $taxConfig
     */
    public function __construct(
        Config $taxConfig
    ) {
        $this->taxConfig = $taxConfig;
    }

    public function aroundMapItem(
        Tax $subject,
        $proceed,
        QuoteDetailsItemInterfaceFactory $itemDataObjectFactory,
        AbstractItem $item,
        $priceIncludesTax,
        $useBaseCurrency,
        $parentCode = null
    ) {
        if ($item->getData('retail_discount_per_items_base_discount') && $useBaseCurrency) {
            $item->setBaseDiscountAmount($item->getBaseDiscountAmount()
                                         + $item->getData('retail_discount_per_items_base_discount'));
        }

        if ($item->getData('retail_discount_per_items_discount') && !$useBaseCurrency) {
            $item->setDiscountAmount($item->getDiscountAmount()
                                     + $item->getData('retail_discount_per_items_discount'));
        }

        return $proceed($itemDataObjectFactory, $item, $priceIncludesTax, $useBaseCurrency, $parentCode);
    }
}