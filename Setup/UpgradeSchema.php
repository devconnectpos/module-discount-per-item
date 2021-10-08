<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace SM\DiscountPerItem\Setup;

use Magento\Framework\DB\Ddl\Table;
use Magento\Framework\Setup\UpgradeSchemaInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @codeCoverageIgnore
 */
class UpgradeSchema implements UpgradeSchemaInterface
{
    /**
     * {@inheritdoc}
     */
    public function upgrade(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        if (version_compare($context->getVersion(), '0.0.5', '<')) {
            $this->addDiscountPerItemColumn($setup);
        }

        if (version_compare($context->getVersion(), '0.0.6', '<')) {
            $this->addDiscountPerItemColumnToOrderItem($setup);
        }
    }

    /**
     * @param SchemaSetupInterface $setup
     * @param OutputInterface      $output
     */
    public function execute(SchemaSetupInterface $setup, OutputInterface $output)
    {
        $output->writeln('  |__ Add discount per item column to quote, sales_order and sales_order_grid tables');
        $this->addDiscountPerItemColumn($setup);
        $output->writeln('  |__ Add discount per item column to order item entity');
        $this->addDiscountPerItemColumnToOrderItem($setup);
    }

    /**
     * @param SchemaSetupInterface $setup
     */
    protected function addDiscountPerItemColumn(SchemaSetupInterface $setup)
    {
        $setup->startSetup();
        $tableNames = ['quote', 'sales_order', 'sales_order_grid'];

        foreach ($tableNames as $tableName) {
            if (!$setup->getConnection()->tableColumnExists($setup->getTable($tableName), 'discount_per_item')) {
                $setup->getConnection()->addColumn(
                    $setup->getTable($tableName),
                    'discount_per_item',
                    [
                        'type'    => Table::TYPE_DECIMAL,
                        'length'  => '12,4',
                        'comment' => 'Discount per item',
                    ]
                );
            }

            if (!$setup->getConnection()->tableColumnExists($setup->getTable($tableName), 'base_discount_per_item')) {
                $setup->getConnection()->addColumn(
                    $setup->getTable($tableName),
                    'base_discount_per_item',
                    [
                        'type'     => Table::TYPE_DECIMAL,
                        'length'   => '12,4',
                        'nullable' => true,
                        'comment'  => 'Discount per item',
                    ]
                );
            }
        }

        $setup->endSetup();
    }

    /**
     * @param SchemaSetupInterface $setup
     */
    protected function addDiscountPerItemColumnToOrderItem(SchemaSetupInterface $setup)
    {
        $setup->startSetup();
        $tableName = $setup->getTable('sales_order_item');

        if (!$setup->getConnection()->tableColumnExists($setup->getTable($tableName), 'cpos_discount_per_item')) {
            $setup->getConnection()->addColumn(
                $tableName,
                'cpos_discount_per_item',
                [
                    'type'     => Table::TYPE_DECIMAL,
                    'length'   => '12,4',
                    'nullable' => true,
                    'comment'  => 'Discount per item',
                ]
            );
        }

        if (!$setup->getConnection()->tableColumnExists($setup->getTable($tableName), 'cpos_discount_per_item_percent')) {
            $setup->getConnection()->addColumn(
                $tableName,
                'cpos_discount_per_item_percent',
                [
                    'type'     => Table::TYPE_DECIMAL,
                    'length'   => '12,4',
                    'nullable' => true,
                    'comment'  => 'Discount per item percent',
                ]
            );
        }

        $setup->endSetup();
    }
}
