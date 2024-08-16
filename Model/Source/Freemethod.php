<?php

namespace BobGroup\BobGo\Model\Source;

/**
 * Bob Go Free Method source implementation
 */
class Freemethod extends Method
{
    /**
     * Returns an array of options for the free method.
     *
     * @return array<int, array<string, string>>
     */
    public function toOptionArray(): array
    {
        // Returns an array of arrays, each of which has a 'value' and a 'label'.
        // The 'value' is the code for the shipping method, and the 'label' is the name of the shipping method.
        $arr = parent::toOptionArray();
        array_unshift($arr, ['value' => '', 'label' => __('None')]);
        return $arr;
    }
}
