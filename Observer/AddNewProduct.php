<?php
/**
 * Copyright © MageWorx. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace MageWorx\OrderEditorInventory\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class AddNewProduct implements ObserverInterface
{
    /**
     * @var \MageWorx\OrderEditorInventory\Api\StockQtyManagerInterface
     */
    private $stockQtyManager;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * AddNewProduct constructor.
     *
     * @param \MageWorx\OrderEditorInventory\Api\StockQtyManagerInterface $stockQtyManager
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(
        \MageWorx\OrderEditorInventory\Api\StockQtyManagerInterface $stockQtyManager,
        \Psr\Log\LoggerInterface $logger
    ) {
        $this->stockQtyManager = $stockQtyManager;
        $this->logger          = $logger;
    }

    /**
     * @inheritDoc
     */
    public function execute(Observer $observer)
    {
        /** @var \MageWorx\OrderEditor\Model\Order\Item $orderItem */
        $orderItem = $observer->getData('order_item');
        $qty       = $orderItem->getQtyOrdered();
        try {
            $this->stockQtyManager->deductQtyFromStock($orderItem);
        } catch (\Exception $exception) {
            $this->logger->critical($exception);
        }
    }
}
