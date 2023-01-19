<?php

namespace uafrica\Customshipping\Model\Source;

/**
 * uafrica Free Method source implementation
 */
class Freemethod extends Method
{
    /**
     * {@inheritdoc}
     */
    public function toOptionArray()
    {
        //Returns an array of arrays, each of which has a 'value' and a 'label'. The 'value' is the code for the shipping method, and the 'label' is the name of the shipping method.
        $arr = parent::toOptionArray();
        array_unshift($arr, ['value' => '', 'label' => __('None')]);
        return $arr;
    }
}
