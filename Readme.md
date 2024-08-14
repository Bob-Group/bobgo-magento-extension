# Installing Magento locally 

**Note:** For more information, visit https://github.com/markshust/docker-magento

1. Make sure you have `composer` installed globally and a GitHub personal access token configured using `composer global config github-oauth.github.com <YOUR_PERSONAL_ACCESS_TOKEN>`
2. Create a new folder, ie: `Documents/Magento`
3. `cd` into the folder 
4. Run command `curl -s https://raw.githubusercontent.com/markshust/docker-magento/master/lib/onelinesetup | bash -s -- bobgomagento.test 2.4.4-p1 community`
5. When asked for the username and password, provide the Abobe public and private key: 

- Public key: `***REMOVED***`
- Private key: `***REMOVED***`

Once configured, do the following to create an admin user (and make life easier for yourself):

- Create Admin User: `bin/magento admin:user:create`
- Disable 2FA: `bin/magento module:disable Magento_TwoFactorAuth`
- Disable Admin Captcha: `bin/magento config:set admin/captcha/enable 0`


### Adobe account details

- Email: `***REMOVED***`
- Password: `***REMOVED***`


# Magento 2 Bob Go Shipping Extension

## Introduction

A complete guide to install Magento Bob Go Shipping extension in Magento 2. 

## Features
>This extension allows you to get real-time shipping rates from Bob Go shipping services and display them to your customers during checkout.

>This extension also allows you to track shipments and get delivery status updates from Bob Go shipping services.

## How to install Magento 2 Bob Go Shipping Extension

### Option 1 (recommended): Install via composer 

Run the following command in Magento 2 root folder:</br>


>_Note: You must have composer installed on your server & at this point this option_

#### 1. Execute the following command to install the module:

``` 
composer require BobGroup/BobGo
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

    <a href="https://gitlab.bob.co.za/bobgo/bobgo-magento-extension/-/archive/main/bobgo-magento-extension-main.zip"> Download Magento 2 Bob Go Shipping Extension </a>

2. Unzip the file and copy contents

3. Create `BobGroup/BobGo` <em>Directory</em>

**It should look like this:** </br>
>{Magento root}/app/code/BobGroup/BobGo/


>**{Magento root}**`/app/code/BobGroup/BobGo/`**{Paste here}**


3. Go to Magento root folder and run all commands below  to install `BobGroup_BobGo`: </br>
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

You need to create an account on Bob Go to get your Store Identified by the API. 

Please visit [Bob Go](https://bobgo.co.za) to create an account.

### ✓ Step 2: Login to Magento Admin

1. Click on **Bob Go** > Bob Go > Enabled for Checkout > Yes </br>


2. and go to `Stores > Configuration > Sales > Delivery Methods` to configure the extension.

### ✓ Step 3: Configure Bob Go Shipping Extension


1. Select `Bob Go` as shipping method.


2. Select `Enable` to enable the extension.


3.  Click `Save Config`


### ✓ Step 4: Address Configurations
#### Admin Configurations: Update Customer Address Fields*
1. `Stores`>`Configurations`>`Customers`
2. `Customer Configuration`>`Name and Address Options`
3. In Input Field `Number of Lines in a Street Address` 
4. Disable System Value `2` > 
5. Lastly ,Change to `3` Lines.

#### Admin Configurations: Update Store Information*

When the extension is **installed** and **enabled**, a new field will be created in the `Store Information` : `Suburb` and Fill in necessary information;

1. `Stores`> `Configuration`> `General`>`General`

2. `Store Information`>`Suburb`

## How it works

## How to use Magento 2 Bob Go Shipping Extension (carrier) to Ship Orders

### ✓ Step 1: Add products to cart(Checkout)

>1. Go to checkout page.
>2. Select shipping address.
>3. Bob Go will collect **Shipping Rates** From various couriers.
>4. Select shipping method.
>5. Place order.

### ✓ Step 1: Create shipment (Admin)
`Sales > Orders > View Order > Ship`

>1. Go to `Sales > Orders` in Magento Admin
>2. Select an order
>3. Click `Ship` button
>4. Select `Bob Go` as shipping method
>5. Click `Submit Shipment` button
