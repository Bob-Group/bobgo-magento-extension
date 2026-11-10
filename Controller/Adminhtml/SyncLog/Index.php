<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Controller\Adminhtml\SyncLog;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;

/**
 * The Bob Go sync log viewer.
 *
 * Every inbound webhook and outbound API call already writes a row; until now
 * there was no way to read them without database access. This is the first place
 * support looks when a merchant says "my order didn't reach Bob Go" — the row
 * carries the direction, the HTTP status, the (PII-redacted) payload and the
 * event id, which together answer the question without a single log grep.
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'BobGroup_BobGo::sync_log';

    /**
     * @return \Magento\Backend\Model\View\Result\Page
     */
    public function execute()
    {
        /** @var \Magento\Backend\Model\View\Result\Page $result */
        $result = $this->resultFactory->create(\Magento\Framework\Controller\ResultFactory::TYPE_PAGE);
        $result->setActiveMenu(self::ADMIN_RESOURCE);
        $result->getConfig()->getTitle()->prepend(__('Bob Go Sync Log'));
        return $result;
    }
}
