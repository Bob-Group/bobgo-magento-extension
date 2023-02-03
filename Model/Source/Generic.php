<?php

namespace bobgo\CustomShipping\Model\Source;


use Magento\Framework\Data\OptionSourceInterface;
use bobgo\CustomShipping\Model\Carrier\CustomShipping;

/**
 * bobgo generic source implementation
 */
class Generic implements OptionSourceInterface
{
    /**
     * @var CustomShipping
     */
    protected CustomShipping $_shippingCustomShipping;

    /**
     * Carrier code
     * @var string
     */
    protected string $_code = '';

    /**
     * @param CustomShipping $shippingCustomShipping
     */
    public function __construct(CustomShipping $shippingCustomShipping)
    {
        $this->_shippingCustomShipping = $shippingCustomShipping;
    }

    /**
     * Returns array to be used in multiselect on back-end
     * @return array
     */
    public function toOptionArray()
    {
        $configData = $this->_shippingCustomShipping->getCode($this->_code);
        $arr = [];
        if ($configData) {
            $arr = array_map(
                function ($code, $title) {
                    return [
                        'value' => $code,
                        'label' => $title
                    ];
                },
                array_keys($configData),
                $configData
            );
        }

        return $arr;
    }

}
