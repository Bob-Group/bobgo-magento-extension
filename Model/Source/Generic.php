<?php

namespace BobGroup\BobGo\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;
use BobGroup\BobGo\Model\Carrier\BobGo;

/**
 * BobGo generic source implementation.
 */
class Generic implements OptionSourceInterface
{
    /**
     * @var BobGo
     */
    protected BobGo $_shippingBobGo;

    /**
     * Carrier code
     *
     * @var string
     */
    protected string $_code = '';

    /**
     * @param BobGo $shippingBobGo
     */
    public function __construct(BobGo $shippingBobGo)
    {
        $this->_shippingBobGo = $shippingBobGo;
    }

    /**
     * Returns array to be used in multiselect on back-end.
     *
     * @return array<int, array<string, string>>
     */
    public function toOptionArray(): array
    {
        $configData = $this->_shippingBobGo->getCode($this->_code);
        $arr = [];
        if ($configData) {
            $arr = array_map(
                function ($code, $title): array {
                    return [
                        'value' => (string) $code,
                        'label' => (string) $title
                    ];
                },
                array_keys($configData),
                $configData
            );
        }

        return $arr;
    }
}
