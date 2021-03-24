<?php
/**
 * Copyright © MageWorx. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace MageWorx\OrderEditorInventory\Api;

use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Model\Order\Item as OrderItem;

interface StockQtyManagerInterface
{
    /**
     * @param OrderItem $orderItem
     * @throws CouldNotSaveException
     * @throws InputException
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function deductQtyFromStock(OrderItem $orderItem): void;

    /**
     * @param OrderItem $orderItem
     * @param float|null $qty
     */
    public function returnQtyToStock(OrderItem $orderItem, float $qty = null): void;
}
