<?php

namespace uafrica\customshipping\Model\Source;


use Magento\Framework\Data\OptionSourceInterface;
use uafrica\Customshipping\Model\Carrier\Customshipping;

/**
 * uAfrica generic source implementation
 */
class Generic implements OptionSourceInterface
{
    /**
     * @var Customshipping
     */
    protected Customshipping $_shippingCustomshipping;

    /**
     * Carrier code
     * @var string
     */
    protected string $_code = '';

    /**
     * @param Customshipping $shippingCustomshipping
     */
    public function __construct(Customshipping $shippingCustomshipping)
    {
        $this->_shippingCustomshipping = $shippingCustomshipping;
    }

    /**
     * Returns array to be used in multiselect on back-end
     * @return array
     */
    public function toOptionArray()
    {
        $configData = $this->_shippingCustomshipping->getCode($this->_code);
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
