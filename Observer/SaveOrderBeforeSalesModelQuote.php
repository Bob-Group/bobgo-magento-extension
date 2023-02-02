<?php

namespace bobgo\CustomShipping\Observer;

use Magento\Framework\DataObject\Copy;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order;

/**
 * Class SaveOrderBeforeSalesModelQuote
 * @package bobgo\CustomShipping\Observer
 * This class is supposed to copy the suburb attribute from the quote to the order object.
 */
class SaveOrderBeforeSalesModelQuote implements ObserverInterface
{
    public Copy $objectCopyService;

    public function __construct(
        Copy $objectCopyService
    ) {
        $this->objectCopyService = $objectCopyService;
    }

    public function execute(Observer $observer)
    {
        $this->objectCopyService->copyFieldsetToTarget(
            'sales_convert_quote',
            'to_order',
            $observer->getEvent()->getQuote(),
            $observer->getEvent()->getOrder()
        );


        return $this;

    }
}
