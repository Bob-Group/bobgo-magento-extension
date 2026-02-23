<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Api;

use Magento\Sales\Api\Data\OrderInterface;

interface OrderMapperInterface
{
    /**
     * @param OrderInterface $order
     * @return array<string,mixed>
     */
    public function mapOrderToPayload(OrderInterface $order): array;

    /**
     * @param OrderInterface $order
     * @return array<string,mixed>
     */
    public function mapOrderToUpdatePayload(OrderInterface $order): array;
}
