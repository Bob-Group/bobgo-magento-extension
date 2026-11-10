<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Block\Adminhtml\System\Config;

use BobGroup\BobGo\Service\ConnectionHealth;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Shows whether the stored credentials actually work.
 *
 * Distinct from "an API key is saved", which is all the config page could tell
 * you before: a key revoked or rotated on the Bob Go side left this page looking
 * perfectly healthy while every call 401'd. The state here is written through from
 * real traffic (see ConnectionHealth), so it reflects the last thing Bob Go
 * actually said to us rather than the last time someone pressed a button.
 */
class ConnectionStatus extends Field
{
    private ConnectionHealth $connectionHealth;

    public function __construct(
        Context $context,
        ConnectionHealth $connectionHealth,
        array $data = []
    ) {
        $this->connectionHealth = $connectionHealth;
        parent::__construct($context, $data);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        $state = $this->connectionHealth->getState();
        $checkedAt = $this->connectionHealth->getCheckedAt();

        [$label, $colour] = $this->present($state);

        $html = sprintf(
            '<span style="font-weight:600;color:%s">%s</span>',
            $this->escapeHtmlAttr($colour),
            $this->escapeHtml($label)
        );

        if ($checkedAt !== null) {
            $html .= sprintf(
                '<span style="color:#666;margin-left:8px">%s</span>',
                $this->escapeHtml(__('since %1 UTC', $checkedAt))
            );
        }

        return $html;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function present(string $state): array
    {
        if ($state === ConnectionHealth::STATE_VALID) {
            return [(string) __('Connected'), '#1c7430'];
        }
        if ($state === ConnectionHealth::STATE_INVALID) {
            return [(string) __('Rejected — check the API key'), '#b02a37'];
        }
        return [(string) __('Not yet contacted'), '#856404'];
    }
}
