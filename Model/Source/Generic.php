<?php
//
//namespace BobGroup\BobGo\Model\Source;
//
//
//use Magento\Framework\Data\OptionSourceInterface;
//use BobGroup\BobGo\Model\Carrier\BobGo;
//
///**
// * bobgo generic source implementation
// */
//class Generic implements OptionSourceInterface
//{
//    /**
//     * @var BobGo
//     */
//    protected BobGo $_shippingBobGo;
//
//    /**
//     * Carrier code
//     * @var string
//     */
//    protected string $_code = '';
//
//    /**
//     * @param BobGo $shippingBobGo
//     */
//    public function __construct(BobGo $shippingBobGo)
//    {
//        $this->_shippingBobGo = $shippingBobGo;
//    }
//
//    /**
//     * Returns array to be used in multiselect on back-end
//     * @return array
//     */
//    public function toOptionArray()
//    {
//        $configData = $this->_shippingBobGo->getCode($this->_code);
//        $arr = [];
//        if ($configData) {
//            $arr = array_map(
//                function ($code, $title) {
//                    return [
//                        'value' => $code,
//                        'label' => $title
//                    ];
//                },
//                array_keys($configData),
//                $configData
//            );
//        }
//
//        return $arr;
//    }
//
//}
