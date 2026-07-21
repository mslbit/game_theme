# Oceanpayment Payment

Oceanpayment payment integration for Magento 2. Supports Credit Card, Apple Pay, Google Pay, WeChat Pay, and Alipay via hosted checkout (merchant-controlled redirect).

## Features

- 5 payment methods in one module: Credit Card, Apple Pay, Google Pay, WeChat Pay, Alipay
- Hosted Checkout with merchant-controlled redirect — PCI DSS compliant, no sensitive data on your server
- Sandbox / Production environment toggle
- SHA256 signature verification for all callbacks
- Async notification (noticeUrl) with reliable order status updates
- Per-method configuration: enable/disable, title, sort order, country restrictions
- Apple Pay domain verification support
- Google Pay merchant configuration

## Installation

### Via Composer

```bash
composer require oceanpayment/module-payment
bin/magento module:enable Oceanpayment_Payment
bin/magento setup:upgrade
bin/magento cache:flush
```

### Manual Installation

1. Copy the `Oceanpayment/Payment` directory to `app/code/`
2. Run:

```bash
bin/magento module:enable Oceanpayment_Payment
bin/magento setup:upgrade
bin/magento cache:flush
```

## Configuration

1. Go to **Stores → Configuration → Sales → Payment Methods → Oceanpayment**
2. Configure **General Settings**:
   - Select Environment (Sandbox / Production)
   - Enter Account, Terminal, and Secure Code from your Oceanpayment merchant dashboard
3. Enable and configure each payment method individually

### Apple Pay Setup

1. Download the [domain verification file](https://dev.oceanpayment.com/files/apple-pay-verification.zip)
2. Deploy to `/.well-known/apple-developer-merchantid-domain-association` on your domain
3. Enter your Apple Merchant Identifier in the Apple Pay configuration section

### Google Pay Setup

1. Enter your Google Merchant ID and Gateway Merchant ID in the Google Pay configuration section

## Payment Flow

1. Customer selects a payment method at checkout
2. Order is created with `pending_payment` status
3. Customer is redirected to Oceanpayment's hosted payment page
4. Customer completes payment
5. Browser redirects back to your store (Back controller)
6. Oceanpayment sends async notification (Notice controller)
7. Order status is updated based on payment result

## Compatibility

- Magento 2.4.4+
- PHP 8.1+

## License

MIT