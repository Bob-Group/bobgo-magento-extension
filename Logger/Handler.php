<?php
namespace BobGroup\BobGo\Logger;

use Monolog\Logger;
use Magento\Framework\Logger\Handler\Base;

class Handler extends Base
{
    protected $fileName = '/var/log/bobgo.log';
    protected $loggerType = Logger::DEBUG;
}
