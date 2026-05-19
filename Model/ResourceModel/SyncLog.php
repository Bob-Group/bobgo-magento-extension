<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class SyncLog extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('bobgo_sync_log', 'entity_id');
    }
}
