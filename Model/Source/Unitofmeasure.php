<?php
//
//namespace BobGroup\BobGo\Model\Source;
//
//class Unitofmeasure extends Generic
//{
//    /**
//     * Carrier code
//     * @var string
//     */
//    protected string $_code = 'unit_of_measure';
//
//}


namespace BobGroup\BobGo\Model\Source;

use Magento\Framework\Option\ArrayInterface;

class Unitofmeasure implements ArrayInterface
{
    public function toOptionArray()
    {
        return [
            ['value' => 'KGS', 'label' => __('Kilograms')],
            ['value' => 'LBS', 'label' => __('Pounds')]
        ];
    }
}
