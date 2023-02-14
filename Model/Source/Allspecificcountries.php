<?php

namespace bobgo\CustomShipping\Model\Source;

class Allspecificcountries extends Generic
{
    /**
     * Carrier code
     * @var string
     */
    protected string $_code = 'allspecificcountries';

    /**
     * @return array
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 1, 'label' => __('Specific Countries')]
        ];
    }

}
