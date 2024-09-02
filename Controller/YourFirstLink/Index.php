<?php
namespace BobGroup\BobGo\Controller\YourFirstLink;

use Psr\Log\LoggerInterface;

class Index extends \Magento\Framework\App\Action\Action
{
    /**
     * @var \Magento\Framework\View\Result\PageFactory
     */
    protected $resultPageFactory;

    protected $logger;

    /**
     * @param \Magento\Framework\App\Action\Context $context
     * @param \Magento\Framework\View\Result\PageFactory resultPageFactory
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        LoggerInterface $logger,
        \Magento\Framework\View\Result\PageFactory $resultPageFactory
    )
    {
        $this->resultPageFactory = $resultPageFactory;
        $this->logger = $logger;
        parent::__construct($context);
    }
    /**
     * Default customer account page
     *
     * @return void
     */
    public function execute()
    {
        $this->logger->info('Page Controller is executed.');
        return $this->resultPageFactory->create();
    }
}
