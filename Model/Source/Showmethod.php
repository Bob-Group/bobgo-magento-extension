<?php

namespace bobgo\CustomShipping\Model\Source;

class Showmethod extends Generic
{
    /**
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['value' => '0', 'label' => __('No')],
        ];
    }

}
