<!--- Document The Magento Bobgo shipping plugin with Read Me Styling, Installation and everything--->

# Magento 2 Bobgo Shipping Extension

## Introduction

Magento 2 Bobgo Shipping Extension is a Magento 2 extension that allows you to integrate your Magento 2 store with Bobgo shipping service. This extension allows you to get real-time shipping rates from Bobgo shipping service and display them to your customers during checkout. This extension also allows you to print shipping labels and track shipments.

## How to install Magento 2 Bobgo Shipping Extension

### ✓ Install via composer (recommend)

Run the following command in Magento 2 root folder:</br>
<Note: You must have composer installed on your server & at this point this option does not work>

``` 
composer require uafrica/bobgo
php bin/magento setup:upgrade
php bin/magento setup:static-content:deploy
```

### ✓ Install via zip file

1. Download the extension
2. Unzip the file
3. Create a folder {Magento root}/app/code/uafrica/Customshipping
4. Copy the content from the unzip folder
5. Go to Magento root folder and run upgrade command line to install `uafrica_Customshipping`:

```
php bin/magento setup:upgrade
php bin/magento setup:static-content:deploy
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
4. Select `Enable Test Mode` to enable test mode
5. Select `Enable Debug Mode` to enable debug mode
6. Click `Save Config`
7. Flush cache (System > Cache Management) and reindex (System > Index Management)
8. Clear generated files(`rm -rf var/generation/*`)
9. Reindex data (`php bin/magento indexer:reindex`)
10. Deploy static content (`php bin/magento setup:static-content:deploy`)
11. Run `php bin/magento cache:clean`
12. Run `php bin/magento cache:flush`
13. Run `php bin/magento setup:upgrade`
14. Run `php bin/magento setup:di:compile`
15. Run `php bin/magento setup:static-content:deploy`
