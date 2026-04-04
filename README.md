# fintrapay-php

Official PHP SDK for the [FintraPay](https://fintrapay.io) crypto payment gateway API. Accept stablecoin payments, payment links, subscriptions, deposit API, payouts, withdrawals, and earn yield -- all with automatic HMAC-SHA256 request signing.

[![Version](https://img.shields.io/badge/version-0.1.0-blue.svg)](https://packagist.org/packages/fintrapay/fintrapay-php)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](https://opensource.org/licenses/MIT)
[![PHP](https://img.shields.io/badge/php-7.4%2B-blue.svg)](https://www.php.net/)

---

## Installation

```bash
composer require fintrapay/fintrapay-php
```

## Quick Start

### Create an Invoice

```php
<?php

require_once 'vendor/autoload.php';

$client = new \FintraPay\FintraPay(
    'xfp_key_your_api_key',
    'xfp_secret_your_api_secret'
);

// Single-token invoice
$invoice = $client->createInvoice('100.00', 'USDT', 'tron');
echo "Payment address: " . $invoice['payment_address'] . "\n";
echo "Invoice ID: " . $invoice['id'] . "\n";

// Multi-token invoice (customer chooses at checkout)
$invoice = $client->createInvoice('250.00', null, null, 'custodial', [
    'accepted_tokens' => ['USDT', 'USDC'],
    'accepted_chains' => ['tron', 'bsc', 'ethereum'],
]);
```

### Verify a Webhook

```php
use FintraPay\Webhook;

$rawBody   = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_FINTRAPAY_SIGNATURE'] ?? '';

if (!Webhook::verifySignature($rawBody, $signature, $webhookSecret)) {
    http_response_code(401);
    exit('Invalid signature');
}

$event = json_decode($rawBody, true);
if ($event['type'] === 'invoice.paid') {
    echo "Invoice " . $event['data']['id'] . " paid!";
}
```

## API Reference

All methods are available on the `\FintraPay\FintraPay` client instance. HMAC-SHA256 signing is handled automatically.

### Invoices

| Method | Description |
|--------|-------------|
| `createInvoice($amount, $currency, $blockchain, ...)` | Create a payment invoice |
| `getInvoice($invoiceId)` | Get invoice by ID |
| `listInvoices($filters)` | List invoices with filters |

### Payouts

| Method | Description |
|--------|-------------|
| `createPayout($toAddress, $amount, $currency, $blockchain, ...)` | Create a single payout |
| `createBatchPayout($currency, $blockchain, $recipients)` | Create a batch payout |
| `getPayout($payoutId)` | Get payout by ID |
| `listPayouts($filters)` | List payouts with filters |
| `listBatchPayouts($page, $pageSize)` | List batch payouts |
| `getBatchPayout($batchId)` | Get batch payout details |

### Withdrawals

| Method | Description |
|--------|-------------|
| `createWithdrawal($amount, $currency, $blockchain)` | Withdraw to your registered wallet |
| `getWithdrawal($withdrawalId)` | Get withdrawal by ID |
| `listWithdrawals($page, $pageSize)` | List withdrawals |

### Earn

| Method | Description |
|--------|-------------|
| `createEarnContract($amount, $currency, $blockchain, $durationMonths)` | Create an Earn contract |
| `getEarnContract($contractId)` | Get Earn contract by ID |
| `listEarnContracts($filters)` | List Earn contracts |
| `withdrawEarnInterest($contractId, $amount)` | Withdraw accrued interest (min $10) |
| `breakEarnContract($contractId)` | Early-break an Earn contract |
| `getInterestHistory($contractId)` | Get daily interest accrual history |

### Refunds

| Method | Description |
|--------|-------------|
| `createRefund($invoiceId, $amount, $toAddress, $reason, ...)` | Create a refund for a paid invoice |
| `getRefund($refundId)` | Get refund by ID |
| `listRefunds($filters)` | List all refunds |
| `listInvoiceRefunds($invoiceId)` | List refunds for a specific invoice |

### Payment Links

| Method | Description |
|--------|-------------|
| `createPaymentLink($title, $options)` | Create a reusable payment link |
| `listPaymentLinks($filters)` | List payment links with filters |
| `getPaymentLink($linkId)` | Get payment link by ID |
| `updatePaymentLink($linkId, $data)` | Update a payment link |

### Subscription Plans

| Method | Description |
|--------|-------------|
| `createSubscriptionPlan($name, $amount, $options)` | Create a subscription plan |
| `listSubscriptionPlans($filters)` | List subscription plans |
| `getSubscriptionPlan($planId)` | Get plan by ID |
| `updateSubscriptionPlan($planId, $data)` | Update a subscription plan |

### Subscriptions

| Method | Description |
|--------|-------------|
| `createSubscription($planId, $customerEmail, $options)` | Create a subscription |
| `listSubscriptions($filters)` | List subscriptions with filters |
| `getSubscription($subscriptionId)` | Get subscription with invoice history |
| `cancelSubscription($subscriptionId, $reason)` | Cancel a subscription |
| `pauseSubscription($subscriptionId)` | Pause an active subscription |
| `resumeSubscription($subscriptionId)` | Resume a paused subscription |

### Deposit API

| Method | Description |
|--------|-------------|
| `createDepositUser($externalUserId, $options)` | Register end user for deposits |
| `getDepositUser($externalUserId)` | Get user with addresses and balances |
| `listDepositUsers($page, $pageSize)` | List deposit users |
| `updateDepositUser($externalUserId, $data)` | Update user (email, label, is_active, is_blocked) |
| `createDepositAddress($externalUserId, $blockchain)` | Generate address for a chain |
| `createAllDepositAddresses($externalUserId)` | Generate addresses for all 7 chains |
| `listDepositAddresses($externalUserId)` | List all addresses for a user |
| `listDeposits($filters)` | List deposit events (optionally by user) |
| `getDeposit($depositId)` | Get single deposit detail |
| `listDepositBalances($externalUserId)` | Get per-token per-chain balances |

### Balance & Fees

| Method | Description |
|--------|-------------|
| `getBalance()` | Get custodial balances across all chains |
| `estimateFees($amount, $currency, $blockchain)` | Estimate transaction fees |

### Support Tickets

| Method | Description |
|--------|-------------|
| `createTicket($subject, $message, $priority)` | Create a support ticket |
| `listTickets($page, $pageSize)` | List support tickets |
| `getTicket($ticketId)` | Get ticket by ID |
| `replyTicket($ticketId, $message)` | Reply to a support ticket |

## Error Handling

The SDK throws typed exceptions for different error scenarios:

```php
use FintraPay\FintraPay;
use FintraPay\FintraPayException;
use FintraPay\AuthenticationException;
use FintraPay\ValidationException;
use FintraPay\RateLimitException;

$client = new FintraPay('xfp_key_...', 'xfp_secret_...');

try {
    $invoice = $client->createInvoice('100.00', 'USDT', 'tron');
} catch (AuthenticationException $e) {
    // Invalid API key or secret (HTTP 401)
    echo "Auth failed: " . $e->getMessage();
} catch (ValidationException $e) {
    // Invalid request parameters (HTTP 422)
    echo "Validation error: " . $e->getMessage();
    print_r($e->getDetails());
} catch (RateLimitException $e) {
    // Too many requests (HTTP 429)
    echo "Rate limited. Retry after " . $e->getRetryAfter() . " seconds";
} catch (FintraPayException $e) {
    // Any other API error
    echo "API error ({$e->getHttpStatus()}): " . $e->getMessage();
}
```

## Webhook Verification

Always verify webhook signatures before processing events. Use the raw request body -- do NOT `json_decode` first.

### Plain PHP

```php
use FintraPay\Webhook;

$rawBody   = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_FINTRAPAY_SIGNATURE'] ?? '';

if (!Webhook::verifySignature($rawBody, $signature, $webhookSecret)) {
    http_response_code(401);
    exit('Invalid signature');
}

$data = json_decode($rawBody, true);
// process event...
```

### Laravel

```php
use Illuminate\Http\Request;
use FintraPay\Webhook;

Route::post('/webhook', function (Request $request) {
    $rawBody   = $request->getContent();
    $signature = $request->header('X-FintraPay-Signature', '');

    if (!Webhook::verifySignature($rawBody, $signature, config('services.fintrapay.webhook_secret'))) {
        return response('Invalid signature', 401);
    }

    $data = $request->json()->all();
    // process event...

    return response('OK', 200);
});
```

### Symfony

```php
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use FintraPay\Webhook;

public function webhookAction(Request $request): Response
{
    $rawBody   = $request->getContent();
    $signature = $request->headers->get('X-FintraPay-Signature', '');

    if (!Webhook::verifySignature($rawBody, $signature, $this->webhookSecret)) {
        return new Response('Invalid signature', 401);
    }

    $data = json_decode($rawBody, true);
    // process event...

    return new Response('OK', 200);
}
```

## Requirements

- PHP 7.4 or later
- `ext-curl`
- `ext-json`

## Supported Chains & Tokens

7 blockchains: TRON, BSC, Ethereum, Solana, Base, Arbitrum, Polygon

6 stablecoins: USDT, USDC, DAI, FDUSD, TUSD, PYUSD

## Links

- [FintraPay Homepage](https://fintrapay.io)
- [API Documentation](https://fintrapay.io/docs)
- [GitHub Repository](https://github.com/Fintra-Ltd/fintrapay-php)

## License

MIT License. See [LICENSE](LICENSE) for details.
