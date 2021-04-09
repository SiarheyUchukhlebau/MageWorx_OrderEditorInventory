<?php
/**
 * Copyright © MageWorx. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageWorx\OrderEditorInventory\Model\Order;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order as OriginalOrder;
use MageWorx\OrderEditor\Model\Config\Source\Shipments\UpdateMode;
use MageWorx\OrderEditor\Helper\Data as Helper;
use Magento\Shipping\Controller\Adminhtml\Order\ShipmentLoaderFactory;
use MageWorx\OrderEditor\Api\OrderRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface as OriginalOrderRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterfaceFactory as OriginalOrderRepositoryInterfaceFactory;
use MageWorx\OrderEditor\Api\OrderItemRepositoryInterface;
use Magento\Sales\Api\OrderPaymentRepositoryInterface;
use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Registry;

/**
 * Class ShipmentManager
 *
 * Add or remove shipments for the order after edit
 */
class ShipmentManager implements \MageWorx\OrderEditor\Api\ShipmentManagerInterface
{
    /**
     * @var Helper
     */
    private $helperData;

    /**
     * @var ShipmentLoaderFactory
     */
    private $shipmentLoaderFactory;

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var OrderItemRepositoryInterface
     */
    private $orderItemRepository;

    /**
     * @var OrderPaymentRepositoryInterface
     */
    private $orderPaymentRepository;

    /**
     * @var TransactionFactory
     */
    private $transactionFactory;

    /**
     * @var Registry
     */
    private $registry;

    /**
     * @var OriginalOrderRepositoryInterface
     */
    private $originalOrderRepository;

    /**
     * @var OriginalOrderRepositoryInterfaceFactory
     */
    private $originalOrderRepositoryFactory;

    /**
     * ShipmentManager constructor.
     *
     * @param Helper $helperData
     * @param Registry $registry
     * @param TransactionFactory $transactionFactory
     * @param ShipmentLoaderFactory $shipmentLoaderFactory
     * @param OrderRepositoryInterface $orderRepository
     * @param OrderItemRepositoryInterface $orderItemRepository
     * @param OrderPaymentRepositoryInterface $orderPaymentRepository
     * @param OriginalOrderRepositoryInterface $originalOrderRepository
     * @param OriginalOrderRepositoryInterfaceFactory $originalOrderRepositoryFactory
     */
    public function __construct(
        Helper $helperData,
        Registry $registry,
        TransactionFactory $transactionFactory,
        ShipmentLoaderFactory $shipmentLoaderFactory,
        OrderRepositoryInterface $orderRepository,
        OrderItemRepositoryInterface $orderItemRepository,
        OrderPaymentRepositoryInterface $orderPaymentRepository,
        OriginalOrderRepositoryInterface $originalOrderRepository,
        OriginalOrderRepositoryInterfaceFactory $originalOrderRepositoryFactory
    ) {
        $this->helperData                     = $helperData;
        $this->registry                       = $registry;
        $this->shipmentLoaderFactory          = $shipmentLoaderFactory;
        $this->transactionFactory             = $transactionFactory;
        $this->orderRepository                = $orderRepository;
        $this->orderItemRepository            = $orderItemRepository;
        $this->orderPaymentRepository         = $orderPaymentRepository;
        $this->originalOrderRepository        = $originalOrderRepository;
        $this->originalOrderRepositoryFactory = $originalOrderRepositoryFactory;
    }

    /**
     * @inheritDoc
     * @throws LocalizedException
     */
    public function updateShipmentsOnOrderEdit(
        \MageWorx\OrderEditor\Model\Order $order
    ): \MageWorx\OrderEditor\Model\Order {
        if ($order->hasShipments()) {
            $itemsBySourceCode = [];
            foreach ($order->getShipmentsCollection() as $shipment) {
                // Unregister by key to prevent exceptions (@see body of the load method)
                $this->registry->unregister('current_shipment');
                // Need to reload order registry in order repository
                $newOrderRepo  = $this->originalOrderRepositoryFactory->create();
                $shipment      = $this->shipmentLoaderFactory->create(['orderRepository' => $newOrderRepo])
                                                             ->setOrderId($order->getId())
                                                             ->setShipmentId($shipment->getId())
                                                             ->load();
                $shipmentItems = $shipment->getItems();
                $sourceCode    = $shipment->getExtensionAttributes()->getSourceCode();
                foreach ($shipmentItems as $shipmentItem) {
                    $orderItemId = $shipmentItem->getOrderItemId();
                    $orderItem   = $order->getItemById($orderItemId);

                    $qtyToShip = $orderItem->getQtyOrdered() -
                        ($orderItem->getQtyRefunded() + $orderItem->getQtyCanceled());

                    $itemsBySourceCode[$sourceCode][$orderItemId] = [
                        'qtyBeforeRemove' => $shipmentItem->getQty(),
                        'isManageStock'   => true,
                        'initialize'      => true,
                        'orderItemId'     => $orderItemId,
                        'product'         => $orderItem->getProductId(),
                        'qtyToShip'       => $qtyToShip,
                        'sku'             => $shipmentItem->getSku(),
                        'orderItem'       => $orderItem
                    ];
                }
            }

            switch ($this->helperData->getUpdateShipmentMode()) {
                case UpdateMode::MODE_UPDATE_ADD:
                    if (!$this->isOrderTotalIncreased($order)) {
                        $this->removeAllShipments($order);
                    }
                    $this->createShipmentForOrder($order, $itemsBySourceCode);
                    break;
                case UpdateMode::MODE_UPDATE_REBUILD:
                    $this->removeAllShipments($order);
                    $this->createShipmentForOrder($order, $itemsBySourceCode);
                    break;
                case UpdateMode::MODE_UPDATE_NOTHING:
                    if ($order->hasRemovedItems()
                        || $order->hasItemsWithDecreasedQty()
                    ) {
                        $this->removeAllShipments($order);
                    }
                    break;
            }
        }

        return $order;
    }

    /**
     * @param \MageWorx\OrderEditor\Model\Order $order
     * @return void
     * @throws LocalizedException
     */
    private function removeAllShipments(\MageWorx\OrderEditor\Model\Order $order): void
    {
        $shipments = $order->getShipmentsCollection();
        foreach ($shipments as $shipment) {
            $shipment->delete();
        }

        $orderItems = $order->getItems();
        foreach ($orderItems as $orderItem) {
            $orderItem->setQtyShipped(0);
            $this->orderItemRepository->save($orderItem);
        }

        $state = OriginalOrder::STATE_PROCESSING;

        $order->setState($state);
        $this->orderRepository->save($order);

        // Need to reload items in order after update
        $order->setItems(null) && $order->getItems();

        $payment = $order->getPayment();
        $payment->setShippingCaptured(0)
                ->setBaseShippingCaptured(0)
                ->setShippingRefunded(0)
                ->setBaseShippingRefunded(0);

        $this->orderPaymentRepository->save($payment);
    }

    /**
     * @param \MageWorx\OrderEditor\Model\Order $order
     * @param array $itemsBySourceCode
     * @return void
     * @throws LocalizedException
     */
    protected function createShipmentForOrder(
        \MageWorx\OrderEditor\Model\Order $order,
        array $itemsBySourceCode = []
    ): void {
        if ($order->canShip()) {
            foreach ($itemsBySourceCode as $sourceCode => $items) {
                $data = $this->getShipmentData($order, $sourceCode, $items);
                // Unregister by key to prevent exceptions (@see body of the load method)
                $this->registry->unregister('current_shipment');
                // Need to reload order registry in order repository
                $newOrderRepo = $this->originalOrderRepositoryFactory->create();
                $shipment     = $this->shipmentLoaderFactory->create(['orderRepository' => $newOrderRepo])
                                                            ->setOrderId($order->getId())
                                                            ->setShipment($data)
                                                            ->load();

                if (!$shipment) {
                    throw new LocalizedException(__('Can not create shipment'));
                }

                // Set original source code
                $shipment->getExtensionAttributes()->setSourceCode($sourceCode);
                $shipment->register();

                $transaction = $this->transactionFactory->create();
                $transaction->addObject($shipment)->addObject($shipment->getOrder())->save();
            }
        }
    }

    /**
     * @inheritdoc
     */
    private function getShipmentData(
        \MageWorx\OrderEditor\Model\Order $order,
        string $sourceCode,
        array $items
    ): array {
        $shipmentItems = [];
        foreach ($items as $item) {
            $orderItemId = $item['orderItemId'];
            if (empty($item['sources'])) {
                $shipmentItems['items'][$orderItemId] = $item['qtyToShip'];
                continue;
            }
            $orderItemId = $item['orderItemId'];
            foreach ($item['sources'] as $source) {
                if ($source['sourceCode'] == $sourceCode) {
                    $qty = ($shipmentItems[$orderItemId] ?? 0) + (float)$source['qtyToDeduct'];

                    $shipmentItems['items'][$orderItemId] = $qty;
                }
            }
        }

        return count($shipmentItems) > 0 ? $shipmentItems : [];
    }

    /**
     * @param \MageWorx\OrderEditor\Model\Order $order
     * @return bool
     */
    private function isOrderTotalIncreased(\MageWorx\OrderEditor\Model\Order $order): bool
    {
        return ($order->hasItemsWithIncreasedQty() || $order->hasAddedItems())
            && (!$order->hasItemsWithDecreasedQty() && !$order->hasRemovedItems());
    }
}
