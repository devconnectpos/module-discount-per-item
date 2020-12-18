<?php

namespace SM\DiscountPerItem\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class SaveDiscountPerItemToOrderItem implements ObserverInterface
{
    
    public function execute(Observer $observer)
    {
        /** @var \Magento\Sales\Model\Order\Item $item */
        $item = $observer->getData('item');
        $productOptions = $item->getData('product_options');
        if (isset($productOptions['info_buyRequest']['retail_discount_per_items_percent'])) {
            $item->setData(
                'cpos_discount_per_item_percent',
                $productOptions['info_buyRequest']['retail_discount_per_items_percent']
            );
        }
    
        if (isset($productOptions['info_buyRequest']['discount_per_item'])) {
            $item->setData('cpos_discount_per_item', $productOptions['info_buyRequest']['discount_per_item']);
        }
    }
}
