# Magento 2 Bob Go Shipping Extension

## Introduction

A complete guide to install Magento Bob Go Shipping extension in Magento 2. 

## Features
>This extension allows you to get real-time shipping rates from Bob Go shipping services and display them to your customers during checkout.

>This extension also allows you to track shipments and get delivery status updates from Bob Go shipping services.

## How to install Magento 2 Bob Go Shipping Extension

### Option 1 (recommended): Install via composer 

Run the following command in Magento 2 root folder:</br>

>_Note: You must have composer installed on your server for this option_

#### 1. Execute the following command to install the module:

``` 
composer require bob-public-utils/bobgo-magento-extension
```
#### 2. Enter following commands to enable the module:

```
bin/magento module:enable BobGroup_BobGo
bin/magento cache:clean
bin/magento cache:flush
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy
```

### Option 2: Install via zip file

1. Download the extension zip file from the link below: </br>

    <a href="https://gitlab.bob.co.za/bob-public-utils/bobgo-magento-extension/-/archive/prod/bobgo-magento-extension-prod.zip"> Download Magento 2 Bob Go Shipping Extension </a>

2. Unzip the file and copy contents

3. Create `BobGroup/BobGo` <em>Directory</em>

**It should look like this:** </br>
>{Magento root}/app/code/BobGroup/BobGo/


>**{Magento root}**`/app/code/BobGroup/BobGo/`**{Paste here}**


4. Go to Magento root folder and run all commands below  to install `BobGroup_BobGo`: </br>
```
bin/magento cache:clean
bin/magento cache:flush
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy
```
_____________________________________________________________________________________________________________________
# After installation


## How to configure Magento 2 Bob Go Shipping Extension 

### ✓ Step 1: Create an account on Bob Go

You need to create an account on Bob Go to get your store identified by the API. 

Please visit [Bob Go](https://bobgo.co.za) to create an account.

### ✓ Step 2: Integrate Magento and Bob Go

1. In the Magento admin portal click on `System > Integrations` (At this point Bob Go should be under Magento integrations)
2. Click to edit the Bob Go integration in the magento admin portal
3. Under Basic settings select `API`
4. Make sure `All` is selected for `Resource Access`
5. Click `Save` and enter your Magento admin portal password
6. The `Integration Details` will be displayed at the bottom of the page
8. On [Bob Go](https://bobgo.co.za) go to `Sales channels > Add channel > Magento` and enter the Magento integration details from step 6 and click `Grant access`
9. On Bob Go go to `Rates at checkout > Settings > Installed channels` and enable your channel for rates at checkout
10. Make sure you have service levels configured and enabled on Bob Go `Rates at checkout > Service levels`

### ✓ Step 3: Login to Magento Admin

1. In the Magento admin portal click on `Stores > Configuration > Sales > Delivery Methods` 
2. Go to the Bob Go delivery method and enable Bob Go rates at checkout
3.  Click `Save Config`
4. A message at the top of the screen will confirm that rates at checkout is enabled correctly: `Bob Go rates at checkout connected.`

#### Admin Configurations: Update Store Information*

When the extension is **installed** and **enabled**, a new field will be created in `Store Information` : `Suburb`. Enter the necessary details:

1. `Stores`> `Configuration`> `General`>`General`
2. `Store Information`>`Suburb`
3. Update the store information and click `Save config`
