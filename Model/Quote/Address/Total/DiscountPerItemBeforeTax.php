<?php
/**
 * Created by mr.vjcspy@gmail.com - khoild@smartosc.com.
 * Date: 05/12/2016
 * Time: 12:01
 */

namespace SM\DiscountPerItem\Model\Quote\Address\Total;

use Magento\Customer\Api\Data\AddressInterfaceFactory as CustomerAddressFactory;
use Magento\Customer\Api\Data\RegionInterfaceFactory as CustomerAddressRegionFactory;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address\Total;
use Magento\Tax\Model\Calculation;
use Magento\Tax\Model\Sales\Total\Quote\CommonTaxCollector;

class DiscountPerItemBeforeTax extends CommonTaxCollector
{

    public static $HIDDEN_TAX = [];
    protected $code = 'retail_discount_per_item';
    /**
     * @var \Magento\Tax\Model\Calculation
     */
    protected $calculation;
    /**
     * @var \Magento\Framework\Pricing\PriceCurrencyInterface
     */
    protected $priceCurrency;
    protected $parentItemsPrice = [];
    /**
     * @var \SM\DiscountPerItem\Helper\DiscountPerItemHelper
     */
    protected $discountPerItemHelper;
    /**
     * @var \Magento\Store\Model\Store
     */
    protected $store;
    protected $roundingDeltas;

    /**
     * DiscountPerItemBeforeTax constructor.
     *
     * @param \Magento\Tax\Model\Config                              $taxConfig
     * @param \Magento\Tax\Api\TaxCalculationInterface               $taxCalculationService
     * @param \Magento\Tax\Api\Data\QuoteDetailsInterfaceFactory     $quoteDetailsDataObjectFactory
     * @param \Magento\Tax\Api\Data\QuoteDetailsItemInterfaceFactory $quoteDetailsItemDataObjectFactory
     * @param \Magento\Tax\Api\Data\TaxClassKeyInterfaceFactory      $taxClassKeyDataObjectFactory
     * @param \Magento\Customer\Api\Data\AddressInterfaceFactory     $customerAddressFactory
     * @param \Magento\Customer\Api\Data\RegionInterfaceFactory      $customerAddressRegionFactory
     * @param \Magento\Tax\Model\Calculation                         $calculation
     * @param \Magento\Framework\Pricing\PriceCurrencyInterface      $priceCurrency
     * @param \SM\DiscountPerItem\Helper\DiscountPerItemHelper       $discountPerItemHelper
     */
    public function __construct(
        \Magento\Tax\Model\Config $taxConfig,
        \Magento\Tax\Api\TaxCalculationInterface $taxCalculationService,
        \Magento\Tax\Api\Data\QuoteDetailsInterfaceFactory $quoteDetailsDataObjectFactory,
        \Magento\Tax\Api\Data\QuoteDetailsItemInterfaceFactory $quoteDetailsItemDataObjectFactory,
        \Magento\Tax\Api\Data\TaxClassKeyInterfaceFactory $taxClassKeyDataObjectFactory,
        \Magento\Customer\Api\Data\AddressInterfaceFactory $customerAddressFactory,
        \Magento\Customer\Api\Data\RegionInterfaceFactory $customerAddressRegionFactory,
        Calculation $calculation,
        \Magento\Framework\Pricing\PriceCurrencyInterface $priceCurrency,
        \SM\DiscountPerItem\Helper\DiscountPerItemHelper $discountPerItemHelper
    ) {
        $this->calculation = $calculation;
        $this->priceCurrency = $priceCurrency;
        $this->discountPerItemHelper = $discountPerItemHelper;
        parent::__construct(
            $taxConfig,
            $taxCalculationService,
            $quoteDetailsDataObjectFactory,
            $quoteDetailsItemDataObjectFactory,
            $taxClassKeyDataObjectFactory,
            $customerAddressFactory,
            $customerAddressRegionFactory
        );
    }

    public function collect(
        Quote $quote,
        \Magento\Quote\Api\Data\ShippingAssignmentInterface $shippingAssignment,
        Total $total
    ) {
        $items = $shippingAssignment->getItems();
        if (!$items) {
            return $this;
        }

        $this->store = $quote->getStore();
        $applyTaxAfterDiscount = $this->_config->applyTaxAfterDiscount($this->store->getStoreId());
        if (!$applyTaxAfterDiscount) {
            return $this;
        }

        parent::collect($quote, $shippingAssignment, $total);
        $customer = $quote->getCustomer();
        $request = $this->getCalculator()
            ->getRateRequest(
                $quote->getShippingAddress(),
                $quote->getBillingAddress(),
                $quote->getCustomerTaxClassId(),
                $this->store->getStoreId(),
                $customer->getId()
            );

        $totalBaseDiscount = $this->prepareDiscountForTaxAmount($shippingAssignment, $request);
        if ($totalBaseDiscount < 0.001 || !$totalBaseDiscount) {
            return $this;
        }

        $discount = $this->getPriceCurrency()->convert($totalBaseDiscount, $this->store);
        $quote->setData('retail_discount_per_item', -$discount);
        $quote->setData('base_retail_discount_per_item', -$totalBaseDiscount);
        $this->_addAmount(-$discount);
        $this->_addBaseAmount(-$totalBaseDiscount);

        return $this;
    }


    public function fetch(
        Quote $quote,
        Total $total
    ) {
        return [
            'code'  => $this->getCode(),
            'title' => $this->getLabel(),
            'value' => $quote->getData('retail_discount_per_item'),
        ];
    }

    protected function prepareDiscountForTaxAmount(
        \Magento\Quote\Api\Data\ShippingAssignmentInterface $shippingAssignment,
        \Magento\Framework\DataObject $request
    ) {
        $baseTotalDiscount = 0;
        $items = $shippingAssignment->getItems();
        $this->calParentItemsPrice($items);
        foreach ($items as $item) {
            /** @var \Magento\Quote\Model\Quote\Item $item */
            if ($item->getParentItem()) {
                continue;
            }

            $request->setProductClassId(
                $item->getProduct()->getTaxClassId()
            );
            $rate = $this->getCalculator()->getRate($request);
            $inclTax = $this->_config->priceIncludesTax($this->store);

            $currencyRate = $this->getPriceCurrency()->convert(1, $this->store);
            $discountPerItem = $this->discountPerItemHelper->getItemDiscount($item, $currencyRate);
            if ($discountPerItem == null || !is_numeric($discountPerItem)) {
                continue;
            }

            if ($item->getHasChildren() && $item->isChildrenCalculated()) {
                $baseItemDiscount = $item->getQty() * $discountPerItem;
                $childDiscount = 0;
                foreach ($item->getChildren() as $child) {
                    /** @var \Magento\Quote\Model\Quote\Item $child */
                    $itemBaseDisCalPrice = $this->discountPerItemHelper->getItemBaseDiscountCalculationPrice($child);
                    $baseItemPrice = $item->getQty()
                        * ($child->getQty() * $itemBaseDisCalPrice)
                        - $child->getBaseDiscountAmount();
                    $itemBaseDiscount = min(
                        $baseItemPrice,
                        $this->deltaRound(
                            $baseItemDiscount * $baseItemPrice / $this->parentItemsPrice[$item->getId()],
                            $item->getId()
                        )
                    );
                    if ($baseItemPrice <= 0.001 || $itemBaseDiscount <= 0.001) {
                        continue;
                    }

                    $itemDiscount = $this->convertPrice($itemBaseDiscount);

                    $child->setData('retail_discount_per_items_base_discount', $itemBaseDiscount)
                        ->setData('retail_discount_per_items_discount', $itemDiscount);

                    $childDiscount += $itemDiscount;

                    switch ($this->_config->getAlgorithm($this->store)) {
                        case Calculation::CALC_UNIT_BASE:
                            $baseTaxableAmount = max(
                                0, $child->getBaseTaxableAmount()
                                - $this->round($itemBaseDiscount / $child->getQty())
                            );
                            $taxableAmount = max(
                                0, $child->getTaxableAmount()
                                - $this->round($itemDiscount / $child->getQty())
                            );
                            $child->setData('base_taxable_amount', $baseTaxableAmount);
                            $child->setData('taxable_amount', $taxableAmount);
                            break;
                        case Calculation::CALC_ROW_BASE:
                        case Calculation::CALC_TOTAL_BASE:
                            $baseTaxableAmount = max(
                                0, $child->getBaseTaxableAmount()
                                - $this->round($itemBaseDiscount)
                            );
                            $taxableAmount = max(
                                0, $child->getTaxableAmount()
                                - $this->round($itemDiscount)
                            );
                            $child->setData('base_taxable_amount', $baseTaxableAmount);
                            $child->setData('taxable_amount', $taxableAmount);
                            break;
                    }

                    /*
                     * Trong trường hợp catalog_tax = incl tax
                     * thì giá discount bị trừ vào grand total là discount đã bao gồm thuế.
                     * Như cần phải tính lại giá thực tế trừ vào grand total.
                     * -> Lam o model: Mage_Tax_Model_Sales_Total_Quote_Tax
                     */

                    if ($inclTax) {
                        $isUnitBase = $this->_config->getAlgorithm($this->store) == Calculation::CALC_UNIT_BASE;
                        $qty = $isUnitBase ? $child->getQty() : 1;
                        $baseTaxAmountOfDiscountPerItem = $this->getCalculator()->calcTaxAmount(
                            $itemBaseDiscount / $qty,
                            $rate,
                            $inclTax,
                            true
                        );
                        DiscountPerItemBeforeTax::$HIDDEN_TAX[] = [
                            'rate_key'   => $child->getId(),
                            'qty'        => $qty,
                            'item'       => $child,
                            'value'      => $this->convertPrice($baseTaxAmountOfDiscountPerItem),
                            'base_value' => $baseTaxAmountOfDiscountPerItem,
                            'incl_tax'   => $inclTax,
                        ];
                    }

                    /*
                     * IMPORTANCE
                     * Vì yêu cầu là tính discount/rule/promotin của magento sau discount per item
                     * nên sẽ sửa lại giá tính discount của promotion.
                     * Set lại giá tính discount
                     */
                    $promotionPriceCalDiscount = $itemBaseDisCalPrice
                        - $this->round($itemBaseDiscount / $child->getQty());
                    $child->setData('discount_calculation_price', $this->convertPrice($promotionPriceCalDiscount));
                    $child->setData('base_discount_calculation_price', $promotionPriceCalDiscount);

                    $baseTotalDiscount += $itemBaseDiscount;
                }
                $item->setData('retail_discount_per_items_discount', $childDiscount);
            } else {
                $baseItemPrice = $item->getQty()
                    * $this->discountPerItemHelper->getItemBaseDiscountCalculationPrice($item)
                    - $item->getBaseDiscountAmount();
                $itemBaseDiscount = min($item->getQty() * $discountPerItem, $baseItemPrice);

                if ($baseItemPrice <= 0.001 || $itemBaseDiscount <= 0.001) {
                    continue;
                }

                $itemDiscount = $this->convertPrice($itemBaseDiscount);
                $item->setData('retail_discount_per_items_base_discount', $itemBaseDiscount)
                    ->setData('retail_discount_per_items_discount', $itemDiscount);

                switch ($this->_config->getAlgorithm($this->store)) {
                    case Calculation::CALC_UNIT_BASE:
                        $baseTaxableAmount = max(
                            0, $item->getBaseTaxableAmount()
                            - $this->round($itemBaseDiscount / $item->getQty())
                        );
                        $taxableAmount = max(
                            0, $item->getTaxableAmount()
                            - $this->round($itemDiscount / $item->getQty())
                        );
                        $item->setData('base_taxable_amount', $baseTaxableAmount);
                        $item->setData('taxable_amount', $taxableAmount);
                        break;
                    case Calculation::CALC_ROW_BASE:
                    case Calculation::CALC_TOTAL_BASE:
                        $baseTaxableAmount = max(0, $item->getBaseTaxableAmount() - $this->round($itemBaseDiscount));
                        $taxableAmount = max(0, $item->getTaxableAmount() - $this->round($itemDiscount));
                        $item->setData('base_taxable_amount', $baseTaxableAmount);
                        $item->setData('taxable_amount', $taxableAmount);
                        break;
                }

                /*
                 * Trong trường hợp catalog_tax = incl tax
                 * thì giá discount bị trừ vào grand total là discount đã bao gồm thuế. Như cần phải tính lại
                 * giá thực tế trừ vào grand total. -> Lam o model: Mage_Tax_Model_Sales_Total_Quote_Tax
                 */
                if ($inclTax) {
                    $isUnitBase = $this->_config->getAlgorithm($this->store) == Calculation::CALC_UNIT_BASE;
                    $qty = $isUnitBase ? $item->getQty() : 1;
                    $baseTaxAmountOfDiscountPerItem = $this->getCalculator()->calcTaxAmount(
                        $itemBaseDiscount / $qty,
                        $rate,
                        $inclTax,
                        true
                    );

                    DiscountPerItemBeforeTax::$HIDDEN_TAX[] = [
                        'rate_key'   => $item->getId(),
                        'qty'        => $qty,
                        'item'       => $item,
                        'value'      => $this->convertPrice($baseTaxAmountOfDiscountPerItem),
                        'base_value' => $baseTaxAmountOfDiscountPerItem,
                        'incl_tax'   => $inclTax,
                    ];
                }
                /*
                 * IMPORTANCE
                 * Vì yêu cầu là tính discount/rule/promotin của magento sau discount per item
                 * nên sẽ sửa lại giá tính discount của promotion.
                 * Set lại giá tính discount
                 * */
                $promotionPriceCalDiscount = $this->discountPerItemHelper->getItemBaseDiscountCalculationPrice($item) -
                    $this->round($itemBaseDiscount / $item->getQty());
                $item->setData('discount_calculation_price', $this->convertPrice($promotionPriceCalDiscount));
                $item->setData('base_discount_calculation_price', $promotionPriceCalDiscount);

                $baseTotalDiscount += $this->round($itemBaseDiscount);
            }
        }

        // Need to do one more rounding to resolve Mr's Leather issue
        return $baseTotalDiscount;
    }

    /**
     * Round price based on previous rounding operation delta
     *
     * @param        $price
     * @param        $parentId
     * @param string $type
     *
     * @return float
     */
    protected function deltaRound($price, $parentId, $type = 'regular')
    {
        if ($price) {
            $rate = (string)$parentId;
            // initialize the delta to a small number to avoid non-deterministic behavior with rounding of 0.5
            $delta = $this->roundingDeltas[$type][$rate] ?? 0.000001;
            $price += $delta;
            $this->roundingDeltas[$type][$rate] = $price - $this->round($price);
            $price = $this->round($price);
        }

        return $price;
    }

    protected function calParentItemsPrice($items)
    {
        foreach ($items as $item) {
            /** @var \Magento\Quote\Model\Quote\Item $item */
            if ($item->getParentItem()) {
                continue;
            }

            if ($item->getHasChildren() && $item->isChildrenCalculated()) {
                $this->parentItemsPrice[$item->getId()] = 0;
                foreach ($item->getChildren() as $child) {
                    $baseItemDisCalPrice = $this->discountPerItemHelper->getItemBaseDiscountCalculationPrice($child);
                    $this->parentItemsPrice[$item->getId()]
                        += $item->getQty() * ($child->getQty() * $baseItemDisCalPrice);
                }
            }
        }
    }

    /**
     * @return \Magento\Tax\Model\Calculation
     */
    protected function getCalculator()
    {
        return $this->calculation;
    }

    /**
     * @return \Magento\Framework\Pricing\PriceCurrencyInterface
     */
    protected function getPriceCurrency()
    {
        return $this->priceCurrency;
    }

    /**
     * @param $amount
     *
     * @return float
     */
    protected function convertPrice($amount)
    {
        return $this->getPriceCurrency()->convert($amount, $this->store);
    }

    /**
     * Round amount
     *
     * @param float $price
     *
     * @return  float
     */
    public function round($price)
    {
        return $this->priceCurrency->round($price);
    }

    /**
     * Retrieve total code name
     *
     * @return string
     */
    public function getCode()
    {
        return 'retail_discount_per_item';
    }
}
