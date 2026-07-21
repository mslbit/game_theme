# How to install Blog for Magento 2

There are 2 different solutions to install extensions:

- Solution #1. Install via Composer (Recommend)
- Solution #2: Ready to paste (Not recommend)
- 
## Requirements
 requires PHP version 7 or greater  
 requires Magento 2 or greater

## Important:
- We recommend you to duplicate your live store on a staging/test site and try installation on it in advanced.
- Back up Magento files and the store database.

## Solution #1. Install via Composer (Recommend)

Coming soon

## Solution #2: Ready to paste (Not recommend)

If you don't want to install via composer, you can use this way.

- Download [the latest version here](https://www.baidu.com)
- Extract `master.zip` file to `app/code/`;
- Go to Magento root folder and run upgrade command line to install `LianLian_LLPay`:

With V3sdk (Must):
```
composer require lianlian/sdkv3
```

```
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento setup:static-content:deploy
```

## FAQs
