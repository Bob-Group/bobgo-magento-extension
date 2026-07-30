<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Model\ResourceModel\SyncLog;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(
            \BobGroup\BobGo\Model\SyncLog::class,
            \BobGroup\BobGo\Model\ResourceModel\SyncLog::class
        );
    }
}
