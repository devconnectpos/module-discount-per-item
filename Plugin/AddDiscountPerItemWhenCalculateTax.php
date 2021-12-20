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
     * @var \Magento\Framework\Pricing\PriceCurrencyInterface
     */
    protected $priceCurrency;

    /**
     * AddDiscountPerItemWhenCalculateTax constructor.
     *
     * @param \Magento\Tax\Model\Config $taxConfig
     */
    public function __construct(
        Config $taxConfig,
        \Magento\Framework\Pricing\PriceCurrencyInterface $priceCurrency
    ) {
        $this->taxConfig = $taxConfig;
        $this->priceCurrency = $priceCurrency;
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
            $baseDiscountAmount = $item->getBaseDiscountAmount() + $item->getData('retail_discount_per_items_base_discount');
            $item->setBaseDiscountAmount($this->priceCurrency->round($baseDiscountAmount));
        }

        if ($item->getData('retail_discount_per_items_discount') && !$useBaseCurrency) {
            $discountAmount = $item->getDiscountAmount() + $item->getData('retail_discount_per_items_discount');
            $item->setDiscountAmount($this->priceCurrency->round($discountAmount));
        }

        if ($item->getBuyRequest()->getData('retail_discount_per_items_percent')) {
            $item->setDiscountPercent($item->getBuyRequest()->getData('retail_discount_per_items_percent'));
        }

        return $proceed($itemDataObjectFactory, $item, $priceIncludesTax, $useBaseCurrency, $parentCode);
    }
}
