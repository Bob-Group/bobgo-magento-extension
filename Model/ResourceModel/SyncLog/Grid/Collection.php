<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Model\ResourceModel\SyncLog\Grid;

use Magento\Framework\Api\Search\SearchResultInterface;
use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;
use Psr\Log\LoggerInterface;

/**
 * Grid-backing collection for the Bob Go sync log.
 *
 * SearchResult rather than the plain collection because the UI listing's data
 * provider needs the SearchResultInterface contract (aggregations, search
 * criteria) that grids are built on.
 */
class Collection extends SearchResult implements SearchResultInterface
{
    public function __construct(
        EntityFactoryInterface $entityFactory,
        LoggerInterface $logger,
        FetchStrategyInterface $fetchStrategy,
        EventManager $eventManager,
        $mainTable = 'bobgo_sync_log',
        $resourceModel = \BobGroup\BobGo\Model\ResourceModel\SyncLog::class
    ) {
        parent::__construct($entityFactory, $logger, $fetchStrategy, $eventManager, $mainTable, $resourceModel);
    }
}
