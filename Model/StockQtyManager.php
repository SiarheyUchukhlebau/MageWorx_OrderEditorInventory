<?php
/**
 * Copyright © MageWorx. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace MageWorx\OrderEditorInventory\Model;

use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\InventoryCatalogApi\Model\GetProductTypesBySkusInterface;
use Magento\InventoryCatalogApi\Model\GetSkusByProductIdsInterface;
use Magento\InventoryConfigurationApi\Model\IsSourceItemManagementAllowedForProductTypeInterface;
use Magento\InventorySales\Model\CheckItemsQuantity;
use Magento\InventorySales\Model\ReturnProcessor\DeductSourceItemQuantityOnRefund;
use Magento\InventorySalesApi\Api\Data\ItemToSellInterfaceFactory;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterface;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterfaceFactory;
use Magento\InventorySalesApi\Api\Data\SalesEventExtensionFactory;
use Magento\InventorySalesApi\Api\Data\SalesEventExtensionInterface;
use Magento\InventorySalesApi\Api\Data\SalesEventInterface;
use Magento\InventorySalesApi\Api\Data\SalesEventInterfaceFactory;
use Magento\InventorySalesApi\Api\PlaceReservationsForSalesEventInterface;
use Magento\InventorySalesApi\Model\GetSkuFromOrderItemInterface;
use Magento\InventorySalesApi\Model\ReturnProcessor\Request\ItemsToRefundInterfaceFactory;
use Magento\InventorySalesApi\Model\StockByWebsiteIdResolverInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\Item as OrderItem;
use Magento\Store\Api\WebsiteRepositoryInterface;
use MageWorx\OrderEditorInventory\Api\StockQtyManagerInterface;

class StockQtyManager implements StockQtyManagerInterface
{
    /**
     * @var PlaceReservationsForSalesEventInterface
     */
    private $placeReservationsForSalesEvent;

    /**
     * @var GetSkusByProductIdsInterface
     */
    private $getSkusByProductIds;

    /**
     * @var WebsiteRepositoryInterface
     */
    private $websiteRepository;

    /**
     * @var SalesChannelInterfaceFactory
     */
    private $salesChannelFactory;

    /**
     * @var SalesEventInterfaceFactory
     */
    private $salesEventFactory;

    /**
     * @var ItemToSellInterfaceFactory
     */
    private $itemsToSellFactory;

    /**
     * @var CheckItemsQuantity
     */
    private $checkItemsQuantity;

    /**
     * @var StockByWebsiteIdResolverInterface
     */
    private $stockByWebsiteIdResolver;

    /**
     * @var GetProductTypesBySkusInterface
     */
    private $getProductTypesBySkus;

    /**
     * @var IsSourceItemManagementAllowedForProductTypeInterface
     */
    private $isSourceItemManagementAllowedForProductType;

    /**
     * @var SalesEventExtensionFactory;
     */
    private $salesEventExtensionFactory;

    /**
     * @var GetSkuFromOrderItemInterface
     */
    private $getSkuFromOrderItem;

    /**
     * @var ItemsToRefundInterfaceFactory
     */
    private $itemsToRefundFactory;

    /**
     * @var DeductSourceItemQuantityOnRefund
     */
    private $deductSourceItemQuantityOnRefund;

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @param PlaceReservationsForSalesEventInterface $placeReservationsForSalesEvent
     * @param GetSkusByProductIdsInterface $getSkusByProductIds
     * @param WebsiteRepositoryInterface $websiteRepository
     * @param SalesChannelInterfaceFactory $salesChannelFactory
     * @param SalesEventInterfaceFactory $salesEventFactory
     * @param ItemToSellInterfaceFactory $itemsToSellFactory
     * @param CheckItemsQuantity $checkItemsQuantity
     * @param StockByWebsiteIdResolverInterface $stockByWebsiteIdResolver
     * @param GetProductTypesBySkusInterface $getProductTypesBySkus
     * @param IsSourceItemManagementAllowedForProductTypeInterface $isSourceItemManagementAllowedForProductType
     * @param SalesEventExtensionFactory $salesEventExtensionFactory
     * @param GetSkuFromOrderItemInterface $getSkuFromOrderItem
     * @param ItemsToRefundInterfaceFactory $itemsToRefundFactory
     * @param DeductSourceItemQuantityOnRefund $deductSourceItemQuantityOnRefund
     * @param OrderRepositoryInterface $orderRepository
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        PlaceReservationsForSalesEventInterface $placeReservationsForSalesEvent,
        GetSkusByProductIdsInterface $getSkusByProductIds,
        WebsiteRepositoryInterface $websiteRepository,
        SalesChannelInterfaceFactory $salesChannelFactory,
        SalesEventInterfaceFactory $salesEventFactory,
        ItemToSellInterfaceFactory $itemsToSellFactory,
        CheckItemsQuantity $checkItemsQuantity,
        StockByWebsiteIdResolverInterface $stockByWebsiteIdResolver,
        GetProductTypesBySkusInterface $getProductTypesBySkus,
        IsSourceItemManagementAllowedForProductTypeInterface $isSourceItemManagementAllowedForProductType,
        SalesEventExtensionFactory $salesEventExtensionFactory,
        GetSkuFromOrderItemInterface $getSkuFromOrderItem,
        ItemsToRefundInterfaceFactory $itemsToRefundFactory,
        DeductSourceItemQuantityOnRefund $deductSourceItemQuantityOnRefund,
        OrderRepositoryInterface $orderRepository
    ) {
        $this->placeReservationsForSalesEvent              = $placeReservationsForSalesEvent;
        $this->getSkusByProductIds                         = $getSkusByProductIds;
        $this->websiteRepository                           = $websiteRepository;
        $this->salesChannelFactory                         = $salesChannelFactory;
        $this->salesEventFactory                           = $salesEventFactory;
        $this->itemsToSellFactory                          = $itemsToSellFactory;
        $this->checkItemsQuantity                          = $checkItemsQuantity;
        $this->stockByWebsiteIdResolver                    = $stockByWebsiteIdResolver;
        $this->getProductTypesBySkus                       = $getProductTypesBySkus;
        $this->isSourceItemManagementAllowedForProductType = $isSourceItemManagementAllowedForProductType;
        $this->salesEventExtensionFactory                  = $salesEventExtensionFactory;
        $this->getSkuFromOrderItem                         = $getSkuFromOrderItem;
        $this->itemsToRefundFactory                        = $itemsToRefundFactory;
        $this->deductSourceItemQuantityOnRefund            = $deductSourceItemQuantityOnRefund;
        $this->orderRepository                             = $orderRepository;
    }

    /**
     * @param OrderItem $orderItem
     * @throws CouldNotSaveException
     * @throws InputException
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function deductQtyFromStock(OrderItem $orderItem): void
    {
        $itemsById = $itemsBySku = $itemsToSell = [];
        $order     = $orderItem->getOrder();
        if (!$order || !$order->getId()) {
            throw new InputException(__('Order Id must be set before processing order item'));
        }

        $itemsById[$orderItem->getProductId()] = $orderItem->getQtyOrdered();
        $productSkus                           = $this->getSkusByProductIds->execute(array_keys($itemsById));
        $productTypes                          = $this->getProductTypesBySkus->execute($productSkus);

        foreach ($productSkus as $productId => $sku) {
            if (false === $this->isSourceItemManagementAllowedForProductType->execute($productTypes[$sku])) {
                continue;
            }

            $itemsBySku[$sku] = (float)$itemsById[$productId];
            $itemsToSell[]    = $this->itemsToSellFactory->create(
                [
                    'sku' => $sku,
                    'qty' => -(float)$itemsById[$productId]
                ]
            );
        }

        $websiteId   = (int)$order->getStore()->getWebsiteId();
        $websiteCode = $this->websiteRepository->getById($websiteId)->getCode();
        $stockId     = (int)$this->stockByWebsiteIdResolver->execute((int)$websiteId)->getStockId();

        $this->checkItemsQuantity->execute($itemsBySku, $stockId);

        /** @var SalesEventExtensionInterface */
        $salesEventExtension = $this->salesEventExtensionFactory->create(
            [
                'data' => [
                    'objectIncrementId' => (string)$order->getIncrementId()
                ]
            ]
        );

        /** @var SalesEventInterface $salesEvent */
        $salesEvent = $this->salesEventFactory->create(
            [
                'type'       => SalesEventInterface::EVENT_ORDER_PLACED,
                'objectType' => SalesEventInterface::OBJECT_TYPE_ORDER,
                'objectId'   => (string)$order->getEntityId()
            ]
        );

        $salesEvent->setExtensionAttributes($salesEventExtension);
        $salesChannel = $this->salesChannelFactory->create(
            [
                'data' => [
                    'type' => SalesChannelInterface::TYPE_WEBSITE,
                    'code' => $websiteCode
                ]
            ]
        );

        $this->placeReservationsForSalesEvent->execute($itemsToSell, $salesChannel, $salesEvent);
    }

    /**
     * @inheritDoc
     */
    public function returnQtyToStock(OrderItem $orderItem, float $qty = null): void
    {
        $order = $orderItem->getOrder();
        if (!$order || !$order->getId()) {
            throw new InputException(__('Order Id must be set before processing order item'));
        }

        $items         = [$orderItem];
        $itemsToRefund = $refundedOrderItemIds = [];

        /** @var OrderItem|OrderItemInterface $orderItem */
        foreach ($items as $orderItem) {
            $sku = $this->getSkuFromOrderItem->execute($orderItem);

            if ($this->isValidItem($sku, $orderItem)) {
                $refundedOrderItemIds[] = $orderItem->getItemId();
                $qtyToReturn            = $qty ?? (float)$orderItem->getQty();
                $processedQty           = $orderItem->getQtyInvoiced() - $orderItem->getQtyRefunded() + $qtyToReturn;
                $itemsToRefund[$sku]    = [
                    'qty'          => ($itemsToRefund[$sku]['qty'] ?? 0) + $qtyToReturn,
                    'processedQty' => ($itemsToRefund[$sku]['processedQty'] ?? 0) + (float)$processedQty
                ];
            }
        }

        $itemsToDeductFromSource = [];
        foreach ($itemsToRefund as $sku => $data) {
            $itemsToDeductFromSource[] = $this->itemsToRefundFactory->create(
                [
                    'sku'          => $sku,
                    'qty'          => $data['qty'],
                    'processedQty' => $data['processedQty']
                ]
            );
        }

        if (!empty($itemsToDeductFromSource)) {
            $this->deductSourceItemQuantityOnRefund->execute(
                $order,
                $itemsToDeductFromSource,
                $refundedOrderItemIds
            );
        }
    }

    /**
     * @param string $sku
     * @param OrderItem $orderItem
     * @return bool
     */
    private function isValidItem(string $sku, OrderItem $orderItem): bool
    {
        // Since simple products which are the part of a grouped product are saved in the database
        // (table sales_order_item) with product type grouped, we manually change the type of
        // product from grouped to simple which support source management.
        $typeId = $orderItem->getProductType() === 'grouped' ? 'simple' : $orderItem->getProductType();

        $productType = $typeId ?: $this->getProductTypesBySkus->execute(
            [$sku]
        )[$sku];

        return $this->isSourceItemManagementAllowedForProductType->execute($productType);
    }
}
