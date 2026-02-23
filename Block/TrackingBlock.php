<?php

namespace BobGroup\BobGo\Block;

use Magento\Framework\View\Element\Template;
use Magento\Framework\Registry;

/**
 * Block for the Bob Go order tracking page.
 *
 * Retrieves shipment tracking data from the Magento registry (populated by
 * the Tracking\Index controller) and makes it available to the template.
 */
class TrackingBlock extends \Magento\Framework\View\Element\Template
{
    /**
     * @var Registry
     */
    protected $registry;

    /**
     * @param Template\Context $context
     * @param Registry $registry
     * @param array<string, mixed> $data
     */
    public function __construct(
        Template\Context $context,
        Registry $registry,
        array $data = []
    ) {
        $this->registry = $registry;
        parent::__construct($context, $data);
    }

    /**
     * Get the shipment tracking data from the registry.
     *
     * @return array<string, mixed>|null Tracking data array, or null if not available
     */
    public function getResponse()
    {
        return $this->registry->registry('shipment_data');
    }
}

