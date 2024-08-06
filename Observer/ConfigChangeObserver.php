<?php

namespace BobGroup\BobGo\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\Curl;
use Psr\Log\LoggerInterface;

class ConfigChangeObserver implements ObserverInterface
{
    protected $scopeConfig;
    protected $curl;
    protected $logger;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        Curl $curl,
        LoggerInterface $logger
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->curl = $curl;
        $this->logger = $logger;
    }

    public function execute(Observer $observer)
    {
        $sectionId = $observer->getEvent()->getSection();

        // Ensure we're working with the 'carriers' section
        if ($sectionId === 'carriers') {
            $isEnabled = $this->scopeConfig->getValue('carriers/bobgo/active', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);

            // Check if the extension is enabled
            if ($isEnabled) {
                $url = 'https://api.dev.bobgo.co.za/rates-at-checkout/magento';

                // Prepare the payload
                $payload = json_encode([
                    "identifier" => "woodemo3.bobgo.co.za",
                    "rate" => [
                        "origin" => [
                            "country" => "ZA",
                            "postal_code" => "0181",
                            "province" => "GP",
                            "city" => "Pretoria",
                            "name" => null,
                            "address1" => "125 Dallas Avenue",
                            "address2" => "Newlands",
                            "address3" => null,
                            "phone" => "",
                            "fax" => "",
                            "email" => null,
                            "address_type" => null,
                            "company_name" => ""
                        ],
                        "destination" => [
                            "country" => "ZA",
                            "postal_code" => "0181",
                            "province" => "GP",
                            "city" => "Pretoria",
                            "name" => null,
                            "address1" => null,
                            "address2" => "",
                            "address3" => null,
                            "phone" => "",
                            "fax" => "",
                            "email" => null,
                            "address_type" => null,
                            "company_name" => "",
                            "shipping_suburb" => "Elarduspark"
                        ],
                        "currency" => "ZAR",
                        "locale" => "en",
                        "items" => [
                            [
                                "name" => "Colours With Variants - Has dimensions - Red",
                                "sku" => "sku-1",
                                "quantity" => 2,
                                "price" => 500,
                                "vendor" => "",
                                "requires_shipping" => true,
                                "taxable" => true,
                                "fulfillment_service" => "manual",
                                "properties" => null,
                                "product_id" => 160824,
                                "grams" => 2000,
                                "height" => 20,
                                "length" => 43,
                                "width" => 63,
                                "variant_id" => 160826,
                                "shipping_class" => "colours",
                                "shipping_class_id" => 41
                            ]
                        ]
                    ]
                ]);

                try {
                    // Set headers
                    $this->curl->addHeader("Accept", "*/*");
                    $this->curl->addHeader("Accept-Encoding", "deflate, gzip, br");
                    $this->curl->addHeader("Content-Type", "application/json; charset=utf-8");
                    $this->curl->addHeader("Host", "api.dev.bobgo.co.za");
                    $this->curl->addHeader("User-Agent", "Magento/2.x");

                    // Send the request
                    $this->curl->post($url, $payload);

                    // Check response status
                    if ($this->curl->getStatus() == 200) {
                        $response = $this->curl->getBody();
                        $this->logger->info('Bob Go API response:', ['response' => $response]);
                    } else {
                        $status = $this->curl->getStatus();
                        $this->logger->error('Bob Go API request failed.', ['status' => $status, 'url' => $url]);
                    }
                } catch (\Exception $e) {
                    $this->logger->error('Bob Go API request error:', ['exception' => $e->getMessage()]);
                }
            }
        }
    }
}
