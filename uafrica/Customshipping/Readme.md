

# Magento 2 Bobgo Shipping Extension

## Introduction

Magento 2 Bobgo Shipping Extension is a Magento 2 extension that allows you to integrate your Magento 2 store with Bobgo shipping service. 

## Features
This extension allows you to get real-time shipping rates from Bobgo shipping service and display them to your customers during checkout.


This extension also allows you to track shipments.

## How to install Magento 2 Bobgo Shipping Extension

### ✓ Install via composer (recommend)

Run the following command in Magento 2 root folder:</br>


<_Note: You must have composer installed on your server & at this point this option does not work,
, option working is manual, although everything is set and ready for installation through composer from https://packagist.org/_ >

``` 
composer require uafrica/bobgo
php bin/magento setup:upgrade
php bin/magento setup:static-content:deploy
```

### ✓ Install via zip file

1. Download the extension

2. Unzip the file

3. Create a folder {Magento root}/app/code/uafrica/Customshipping

4. Copy the content from the unzip folder(Registration.php, etc, view, etc) to {Magento root}/app/code/uafrica/Customshipping)


5. Go to Magento root folder and run upgrade `bin/magento setup:upgrade` command line to install `uafrica_Customshipping`:

```
Bin/magento cache:clean
Bin/magento cache:flush
Bin/magento setup:upgrade
Bin/magento setup:di:compile
```

## How to configure Magento 2 Bobgo Shipping Extension

### ✓ Step 1: Create an account on Bobgo

You need to create an account on Bobgo to get API key and API secret<Not Sure About This At This Point> . Please visit [Bobgo](https://bobgo.co.za) to create an account.

### ✓ Step 2: Login to Magento Admin

Login to Magento Admin and go to `Stores > Configuration > Sales > Delivery Methods`


### ✓ Step 3: Configure Bobgo Shipping Extension

1. Select `Bobgo` as shipping method
2. Enter API key and API secret
3. Select `Enable` to enable the extension
4. Select `Enable Debug Mode` to enable debug mode
5. Click `Save Config`
6. Flush cache (System > Cache Management) and reindex (System > Index Management)
7. Clear generated files(`rm -rf var/generation/*`)
8. Reindex data (`php bin/magento indexer:reindex`)
9. Deploy static content (`php bin/magento setup:static-content:deploy`)
10. Run `php bin/magento cache:clean`
11. Run `php bin/magento cache:flush`
12. Run `php bin/magento setup:upgrade`
13. Run `php bin/magento setup:di:compile`
14. Run `php bin/magento setup:static-content:deploy`

## How to use Magento 2 Bobgo Shipping Extension (carrier) to ship orders

### ✓ Step 1: Create shipment

1. Go to `Sales > Orders` in Magento Admin
2. Select an order
3. Click `Ship` button
4. Select `Bobgo` as shipping method
5. Click `Submit Shipment` button
