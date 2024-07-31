<?php
namespace BobGroup\BobGo\Logger;

use Magento\Framework\Logger\Handler\Base;
use Monolog\Logger;

class Handler extends Base
{
    protected $fileName = '/var/log/bobgo_plugin.log';
    protected $loggerType = Logger::DEBUG;
}
