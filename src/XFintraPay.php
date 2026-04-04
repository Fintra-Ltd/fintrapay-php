<?php

declare(strict_types=1);

namespace FintraPay;

/**
 * Base exception for the FintraPay SDK.
 */
class FintraPayException extends \Exception
{
    /** @var string */
    protected $errorCode;

    /** @var int */
    protected $httpStatus;

    /** @var array<string, mixed> */
    protected $details;

    /**
     * @param string               $message
     * @param string               $errorCode
     * @param int                  $httpStatus
     * @param array<string, mixed> $details
     */
    public function __construct(
        string $message,
        string $errorCode = '',
        int $httpStatus = 0,
        array $details = []
    ) {
        parent::__construct($message, $httpStatus);
        $this->errorCode  = $errorCode;
        $this->httpStatus = $httpStatus;
        $this->details    = $details;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    /** @return array<string, mixed> */
    public function getDetails(): array
    {
        return $this->details;
    }
}

/**
 * Raised when API authentication fails (HTTP 401).
 */
class AuthenticationException extends FintraPayException
{
}

/**
 * Raised when request validation fails (HTTP 422).
 */
class ValidationException extends FintraPayException
{
}

/**
 * Raised when the rate limit is exceeded (HTTP 429).
 */
class RateLimitException extends FintraPayException
{
    /** @var int Seconds to wait before retrying. */
    protected $retryAfter;

    /**
     * @param string               $message
     * @param int                  $retryAfter
     * @param string               $errorCode
     * @param int                  $httpStatus
     * @param array<string, mixed> $details
     */
    public function __construct(
        string $message,
        int $retryAfter = 0,
        string $errorCode = '',
        int $httpStatus = 429,
        array $details = []
    ) {
        parent::__construct($message, $errorCode, $httpStatus, $details);
        $this->retryAfter = $retryAfter;
    }

    public function getRetryAfter(): int
    {
        return $this->retryAfter;
    }
}

/**
 * FintraPay API client.
 *
 * Handles HMAC-SHA256 request signing automatically.
 *
 * Usage:
 *     $client = new \FintraPay\FintraPay('xfp_key_...', 'xfp_secret_...');
 *     $invoice = $client->createInvoice('100.00', 'USDT', 'tron');
 */
class FintraPay
{
    public const DEFAULT_BASE_URL = 'https://fintrapay.io/v1';

    /** @var string */
    private $apiKey;

    /** @var string */
    private $apiSecret;

    /** @var string */
    private $baseUrl;

    /** @var int Timeout in seconds. */
    private $timeout;

    /**
     * @param string      $apiKey    Your FintraPay API key (xfp_key_...).
     * @param string      $apiSecret Your FintraPay API secret (xfp_secret_...).
     * @param string|null $baseUrl   Override the default base URL.
     * @param int         $timeout   HTTP request timeout in seconds.
     *
     * @throws \InvalidArgumentException If api_key or api_secret is empty.
     */
    public function __construct(
        string $apiKey,
        string $apiSecret,
        ?string $baseUrl = null,
        int $timeout = 30
    ) {
        if ($apiKey === '' || $apiSecret === '') {
            throw new \InvalidArgumentException('api_key and api_secret are required');
        }

        $this->apiKey    = $apiKey;
        $this->apiSecret = $apiSecret;
        $this->baseUrl   = rtrim($baseUrl ?? self::DEFAULT_BASE_URL, '/');
        $this->timeout   = $timeout;
    }

    // ── Request signing ─────────────────────────────────────────

    /**
     * Compute HMAC-SHA256 authentication headers.
     *
     * @param string $method HTTP method (GET, POST, etc.).
     * @param string $path   Request path (e.g. /invoices).
     * @param string $body   JSON-encoded request body (empty string for GET).
     *
     * @return array<string, string> Headers array.
     */
    private function sign(string $method, string $path, string $body = ''): array
    {
        $timestamp = (string) time();
        $payload   = "{$timestamp}\n{$method}\n{$path}\n{$body}";
        $signature = hash_hmac('sha256', $payload, $this->apiSecret);

        return [
            'X-API-Key'    => $this->apiKey,
            'X-Timestamp'  => $timestamp,
            'X-Signature'  => $signature,
        ];
    }

    /**
     * Send an authenticated HTTP request.
     *
     * @param string                    $method HTTP method.
     * @param string                    $path   API path (e.g. /invoices).
     * @param array<string, mixed>|null $data   Request body data (for POST/PATCH).
     *
     * @return array<string, mixed>|null Decoded response or null for 204.
     *
     * @throws FintraPayException On API or network errors.
     */
    private function request(string $method, string $path, ?array $data = null)
    {
        $url    = $this->baseUrl . $path;
        $body   = ($data !== null) ? json_encode($data, JSON_UNESCAPED_SLASHES) : '';
        $method = strtoupper($method);

        $headers = $this->sign($method, $path, $body);
        $headers['Content-Type'] = 'application/json';

        $curlHeaders = [];
        foreach ($headers as $name => $value) {
            $curlHeaders[] = "{$name}: {$value}";
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $curlHeaders,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        if ($body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        // Capture response headers for Retry-After.
        $responseHeaders = [];
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, string $header) use (&$responseHeaders): int {
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return strlen($header);
        });

        $responseBody = curl_exec($ch);
        $statusCode   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError    = curl_error($ch);
        $curlErrno    = curl_errno($ch);
        curl_close($ch);

        if ($curlErrno !== 0) {
            throw new FintraPayException(
                "HTTP request failed: {$curlError}",
                'network_error',
                0
            );
        }

        return $this->handleResponse($statusCode, (string) $responseBody, $responseHeaders);
    }

    /**
     * Handle the API response, raising typed exceptions on errors.
     *
     * @param int                  $statusCode      HTTP status code.
     * @param string               $body            Raw response body.
     * @param array<string, string> $responseHeaders Parsed response headers (lowercased keys).
     *
     * @return array<string, mixed>|null Decoded JSON or null for 204.
     *
     * @throws AuthenticationException On 401.
     * @throws ValidationException     On 422.
     * @throws RateLimitException      On 429.
     * @throws FintraPayException     On any other error status.
     */
    private function handleResponse(int $statusCode, string $body, array $responseHeaders = [])
    {
        if ($statusCode === 204) {
            return null;
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            $data = ['error' => $body];
        }

        if ($statusCode >= 200 && $statusCode < 300) {
            return $data;
        }

        $errorMsg  = $data['error'] ?? 'Unknown error';
        $errorCode = $data['code'] ?? '';
        $details   = $data['details'] ?? [];

        switch ($statusCode) {
            case 401:
                throw new AuthenticationException($errorMsg, $errorCode, 401);

            case 422:
                throw new ValidationException($errorMsg, $errorCode, 422, $details);

            case 429:
                $retryAfter = (int) ($responseHeaders['retry-after'] ?? 60);
                throw new RateLimitException($errorMsg, $retryAfter, $errorCode, 429);

            default:
                throw new FintraPayException($errorMsg, $errorCode, $statusCode, $details);
        }
    }

    // ── Invoices ────────────────────────────────────────────────

    /**
     * Create a payment invoice.
     *
     * Single token:
     *     $client->createInvoice('100.00', 'USDT', 'tron');
     *
     * Multi-token (customer chooses on checkout):
     *     $client->createInvoice('100.00', null, null, 'custodial', [
     *         'accepted_tokens' => ['USDT', 'USDC'],
     *         'accepted_chains' => ['tron', 'bsc'],
     *     ]);
     *
     * @param string               $amount     Payment amount.
     * @param string|null          $currency   Token symbol (e.g. USDT). Null for multi-token.
     * @param string|null          $blockchain Chain name. Null for multi-token.
     * @param string               $mode       'custodial' or 'non_custodial'.
     * @param array<string, mixed> $options    Optional: accepted_tokens, accepted_chains,
     *                                         external_id, expiry_minutes, expires_at,
     *                                         success_url, cancel_url.
     *
     * @return array<string, mixed> Invoice data (includes checkout_url).
     */
    public function createInvoice(
        string $amount,
        ?string $currency = null,
        ?string $blockchain = null,
        string $mode = 'custodial',
        array $options = []
    ): array {
        $body = ['amount' => $amount, 'mode' => $mode];

        if ($currency !== null) {
            $body['currency'] = $currency;
        }
        if ($blockchain !== null) {
            $body['blockchain'] = $blockchain;
        }

        $optionalFields = ['accepted_tokens', 'accepted_chains', 'external_id', 'expiry_minutes', 'expires_at', 'success_url', 'cancel_url'];
        foreach ($optionalFields as $field) {
            if (isset($options[$field])) {
                $body[$field] = $options[$field];
            }
        }

        return $this->request('POST', '/invoices', $body);
    }

    /**
     * Get invoice by ID.
     *
     * @param string $invoiceId Invoice UUID.
     *
     * @return array<string, mixed>
     */
    public function getInvoice(string $invoiceId): array
    {
        return $this->request('GET', "/invoices/{$invoiceId}");
    }

    /**
     * List invoices with optional filters.
     *
     * @param array<string, mixed> $filters Optional: status, blockchain, currency, mode, page, page_size.
     *
     * @return array<string, mixed>
     */
    public function listInvoices(array $filters = []): array
    {
        $params = array_merge(['page' => 1, 'page_size' => 20], $filters);
        $query  = http_build_query($params);

        return $this->request('GET', "/invoices?{$query}");
    }

    // ── Payouts ─────────────────────────────────────────────────

    /**
     * Create a single payout to any address.
     *
     * @param string      $toAddress  Recipient wallet address.
     * @param string      $amount     Payout amount.
     * @param string      $currency   Token symbol.
     * @param string      $blockchain Chain name.
     * @param string      $reason     Reason (payment, refund, reward, airdrop, salary, other).
     * @param string|null $reference  Merchant's internal reference.
     *
     * @return array<string, mixed>
     */
    public function createPayout(
        string $toAddress,
        string $amount,
        string $currency,
        string $blockchain,
        string $reason = 'payment',
        ?string $reference = null
    ): array {
        $body = [
            'to_address' => $toAddress,
            'amount'     => $amount,
            'currency'   => $currency,
            'blockchain' => $blockchain,
            'reason'     => $reason,
        ];

        if ($reference !== null) {
            $body['reference'] = $reference;
        }

        return $this->request('POST', '/payouts', $body);
    }

    /**
     * Create a batch payout.
     *
     * @param string                      $currency   Token symbol.
     * @param string                      $blockchain Chain name.
     * @param array<int, array<string, mixed>> $recipients Array of recipients:
     *     [['to_address' => '0x...', 'amount' => '50.00', 'reference' => 'sal-001'], ...]
     *
     * @return array<string, mixed>
     */
    public function createBatchPayout(
        string $currency,
        string $blockchain,
        array $recipients
    ): array {
        return $this->request('POST', '/payouts/batch', [
            'currency'   => $currency,
            'blockchain' => $blockchain,
            'recipients' => $recipients,
        ]);
    }

    /**
     * Get payout by ID.
     *
     * @param string $payoutId Payout UUID.
     *
     * @return array<string, mixed>
     */
    public function getPayout(string $payoutId): array
    {
        return $this->request('GET', "/payouts/{$payoutId}");
    }

    /**
     * List payouts with optional filters.
     *
     * @param array<string, mixed> $filters Optional: status, page, page_size.
     *
     * @return array<string, mixed>
     */
    public function listPayouts(array $filters = []): array
    {
        $params = array_merge(['page' => 1, 'page_size' => 20], $filters);
        $query  = http_build_query($params);

        return $this->request('GET', "/payouts?{$query}");
    }

    // ── Withdrawals ─────────────────────────────────────────────

    /**
     * Withdraw to your registered wallet.
     *
     * @param string $amount     Withdrawal amount.
     * @param string $currency   Token symbol.
     * @param string $blockchain Chain name.
     *
     * @return array<string, mixed>
     */
    public function createWithdrawal(
        string $amount,
        string $currency,
        string $blockchain
    ): array {
        return $this->request('POST', '/withdrawals', [
            'amount'     => $amount,
            'currency'   => $currency,
            'blockchain' => $blockchain,
        ]);
    }

    /**
     * Get withdrawal by ID.
     *
     * @param string $withdrawalId Withdrawal UUID.
     *
     * @return array<string, mixed>
     */
    public function getWithdrawal(string $withdrawalId): array
    {
        return $this->request('GET', "/withdrawals/{$withdrawalId}");
    }

    /**
     * List withdrawals.
     *
     * @param int $page     Page number (1-based).
     * @param int $pageSize Results per page.
     *
     * @return array<string, mixed>
     */
    public function listWithdrawals(int $page = 1, int $pageSize = 20): array
    {
        return $this->request('GET', "/withdrawals?page={$page}&page_size={$pageSize}");
    }

    // ── Earn ────────────────────────────────────────────────────

    /**
     * Create an Earn contract.
     *
     * @param string $amount         Principal amount.
     * @param string $currency       Token symbol.
     * @param string $blockchain     Chain name.
     * @param int    $durationMonths Lock duration (1, 3, 6, or 12).
     *
     * @return array<string, mixed>
     */
    public function createEarnContract(
        string $amount,
        string $currency,
        string $blockchain,
        int $durationMonths
    ): array {
        return $this->request('POST', '/earn/contracts', [
            'amount'          => $amount,
            'currency'        => $currency,
            'blockchain'      => $blockchain,
            'duration_months' => $durationMonths,
        ]);
    }

    /**
     * Get Earn contract by ID.
     *
     * @param string $contractId Contract UUID.
     *
     * @return array<string, mixed>
     */
    public function getEarnContract(string $contractId): array
    {
        return $this->request('GET', "/earn/contracts/{$contractId}");
    }

    /**
     * List Earn contracts with optional filters.
     *
     * @param array<string, mixed> $filters Optional: status, page.
     *
     * @return array<string, mixed>
     */
    public function listEarnContracts(array $filters = []): array
    {
        $params = array_merge(['page' => 1], $filters);
        $query  = http_build_query($params);

        return $this->request('GET', "/earn/contracts?{$query}");
    }

    /**
     * Withdraw accrued interest from an Earn contract (minimum $10).
     *
     * @param string $contractId Contract UUID.
     * @param string $amount     Amount to withdraw.
     *
     * @return array<string, mixed>
     */
    public function withdrawEarnInterest(string $contractId, string $amount): array
    {
        return $this->request('POST', "/earn/contracts/{$contractId}/withdraw-interest", [
            'amount' => $amount,
        ]);
    }

    /**
     * Early break an Earn contract.
     *
     * @param string $contractId Contract UUID.
     *
     * @return array<string, mixed>
     */
    public function breakEarnContract(string $contractId): array
    {
        return $this->request('POST', "/earn/contracts/{$contractId}/break");
    }

    // ── Refunds ─────────────────────────────────────────────────

    /**
     * Create a refund for a paid invoice.
     *
     * Partial refunds are supported -- multiple refunds per invoice until
     * the total refunded equals the invoice amount.
     *
     * Statuses: pending -> processing -> completed | rejected
     *
     * @param string      $invoiceId     Invoice UUID to refund.
     * @param string      $amount        Refund amount in the invoice's currency.
     * @param string      $toAddress     Customer's wallet address.
     * @param string      $reason        Explanation for the refund.
     * @param string|null $customerEmail Optional customer email for notification.
     *
     * @return array<string, mixed>
     */
    public function createRefund(
        string $invoiceId,
        string $amount,
        string $toAddress,
        string $reason,
        ?string $customerEmail = null
    ): array {
        $body = [
            'amount'     => $amount,
            'to_address' => $toAddress,
            'reason'     => $reason,
        ];

        if ($customerEmail !== null) {
            $body['customer_email'] = $customerEmail;
        }

        return $this->request('POST', "/invoices/{$invoiceId}/refunds", $body);
    }

    /**
     * Get a refund by ID.
     *
     * @param string $refundId Refund UUID.
     *
     * @return array<string, mixed>
     */
    public function getRefund(string $refundId): array
    {
        return $this->request('GET', "/refunds/{$refundId}");
    }

    /**
     * List all refunds with optional filters.
     *
     * @param array<string, mixed> $filters Optional: status, page, page_size.
     *
     * @return array<string, mixed>
     */
    public function listRefunds(array $filters = []): array
    {
        $params = array_merge(['page' => 1, 'page_size' => 20], $filters);
        $query  = http_build_query($params);

        return $this->request('GET', "/refunds?{$query}");
    }

    /**
     * List all refunds for a specific invoice.
     *
     * @param string $invoiceId Invoice UUID.
     *
     * @return array<string, mixed>
     */
    public function listInvoiceRefunds(string $invoiceId): array
    {
        return $this->request('GET', "/invoices/{$invoiceId}/refunds");
    }

    // ── Balance ─────────────────────────────────────────────────

    /**
     * Get custodial balances across all chains.
     *
     * @return array<string, mixed>
     */
    public function getBalance(): array
    {
        return $this->request('GET', '/balance');
    }

    // ── Batch Payouts ──────────────────────────────────────────

    /**
     * List batch payouts.
     *
     * @param int $page     Page number (1-based).
     * @param int $pageSize Results per page.
     *
     * @return array<string, mixed>
     */
    public function listBatchPayouts(int $page = 1, int $pageSize = 20): array
    {
        return $this->request('GET', "/payouts/batches?page={$page}&page_size={$pageSize}");
    }

    /**
     * Get batch payout by ID.
     *
     * @param string $batchId Batch UUID.
     *
     * @return array<string, mixed>
     */
    public function getBatchPayout(string $batchId): array
    {
        return $this->request('GET', "/payouts/batches/{$batchId}");
    }

    // ── Fees ────────────────────────────────────────────────────

    /**
     * Estimate fees for a transaction.
     *
     * @param string $amount     Transaction amount.
     * @param string $currency   Token symbol.
     * @param string $blockchain Chain name.
     *
     * @return array<string, mixed>
     */
    public function estimateFees(string $amount, string $currency, string $blockchain): array
    {
        return $this->request('POST', '/fees/estimate', [
            'amount'     => $amount,
            'currency'   => $currency,
            'blockchain' => $blockchain,
        ]);
    }

    // ── Tickets ─────────────────────────────────────────────────

    /**
     * Create a support ticket.
     *
     * @param string $subject  Ticket subject.
     * @param string $message  Ticket message body.
     * @param string $priority Priority level (low, medium, high).
     *
     * @return array<string, mixed>
     */
    public function createTicket(string $subject, string $message, string $priority = 'medium'): array
    {
        return $this->request('POST', '/tickets', [
            'subject'  => $subject,
            'message'  => $message,
            'priority' => $priority,
        ]);
    }

    /**
     * List support tickets.
     *
     * @param int $page     Page number (1-based).
     * @param int $pageSize Results per page.
     *
     * @return array<string, mixed>
     */
    public function listTickets(int $page = 1, int $pageSize = 20): array
    {
        return $this->request('GET', "/tickets?page={$page}&page_size={$pageSize}");
    }

    /**
     * Get support ticket by ID.
     *
     * @param string $ticketId Ticket UUID.
     *
     * @return array<string, mixed>
     */
    public function getTicket(string $ticketId): array
    {
        return $this->request('GET', "/tickets/{$ticketId}");
    }

    /**
     * Reply to a support ticket.
     *
     * @param string $ticketId Ticket UUID.
     * @param string $message  Reply message body.
     *
     * @return array<string, mixed>
     */
    public function replyTicket(string $ticketId, string $message): array
    {
        return $this->request('POST', "/tickets/{$ticketId}/reply", [
            'message' => $message,
        ]);
    }

    // ── Earn Interest History ───────────────────────────────────

    /**
     * Get interest accrual history for an Earn contract.
     *
     * @param string $contractId Contract UUID.
     *
     * @return array<string, mixed>
     */
    public function getInterestHistory(string $contractId): array
    {
        return $this->request('GET', "/earn/contracts/{$contractId}/interest-history");
    }

    // ── Payment Links ──────────────────────────────────────────

    /**
     * Create a payment link.
     *
     * @param string               $title   Payment link title.
     * @param array<string, mixed> $options Optional: amount, currency, blockchain, description, etc.
     *
     * @return array<string, mixed>
     */
    public function createPaymentLink(string $title, array $options = []): array
    {
        $body = array_merge(['title' => $title], $options);

        return $this->request('POST', '/payment-links', $body);
    }

    /**
     * List payment links with optional filters.
     *
     * @param array<string, mixed> $options Optional: status, page, page_size.
     *
     * @return array<string, mixed>
     */
    public function listPaymentLinks(array $options = []): array
    {
        $params = array_merge(['page' => 1, 'page_size' => 20], $options);
        $query  = http_build_query($params);

        return $this->request('GET', "/payment-links?{$query}");
    }

    /**
     * Get payment link by ID.
     *
     * @param string $linkId Payment link UUID.
     *
     * @return array<string, mixed>
     */
    public function getPaymentLink(string $linkId): array
    {
        return $this->request('GET', "/payment-links/{$linkId}");
    }

    /**
     * Update a payment link.
     *
     * @param string               $linkId Payment link UUID.
     * @param array<string, mixed> $data   Fields to update.
     *
     * @return array<string, mixed>
     */
    public function updatePaymentLink(string $linkId, array $data): array
    {
        return $this->request('PATCH', "/payment-links/{$linkId}", $data);
    }

    // ── Subscription Plans ─────────────────────────────────────

    /**
     * Create a subscription plan.
     *
     * @param string               $name    Plan name.
     * @param string               $amount  Billing amount.
     * @param array<string, mixed> $options Optional: currency, blockchain, interval, description, etc.
     *
     * @return array<string, mixed>
     */
    public function createSubscriptionPlan(string $name, string $amount, array $options = []): array
    {
        $body = array_merge(['name' => $name, 'amount' => $amount], $options);

        return $this->request('POST', '/subscription-plans', $body);
    }

    /**
     * List subscription plans with optional filters.
     *
     * @param array<string, mixed> $options Optional: status, page, page_size.
     *
     * @return array<string, mixed>
     */
    public function listSubscriptionPlans(array $options = []): array
    {
        $params = array_merge(['page' => 1, 'page_size' => 20], $options);
        $query  = http_build_query($params);

        return $this->request('GET', "/subscription-plans?{$query}");
    }

    /**
     * Get subscription plan by ID.
     *
     * @param string $planId Subscription plan UUID.
     *
     * @return array<string, mixed>
     */
    public function getSubscriptionPlan(string $planId): array
    {
        return $this->request('GET', "/subscription-plans/{$planId}");
    }

    /**
     * Update a subscription plan.
     *
     * @param string               $planId Subscription plan UUID.
     * @param array<string, mixed> $data   Fields to update.
     *
     * @return array<string, mixed>
     */
    public function updateSubscriptionPlan(string $planId, array $data): array
    {
        return $this->request('PATCH', "/subscription-plans/{$planId}", $data);
    }

    // ── Subscriptions ──────────────────────────────────────────

    /**
     * Create a subscription.
     *
     * @param string               $planId        Subscription plan UUID.
     * @param string               $customerEmail Customer email address.
     * @param array<string, mixed> $options       Optional: metadata, start_date, etc.
     *
     * @return array<string, mixed>
     */
    public function createSubscription(string $planId, string $customerEmail, array $options = []): array
    {
        $body = array_merge([
            'plan_id'        => $planId,
            'customer_email' => $customerEmail,
        ], $options);

        return $this->request('POST', '/subscriptions', $body);
    }

    /**
     * List subscriptions with optional filters.
     *
     * @param array<string, mixed> $options Optional: status, plan_id, page, page_size.
     *
     * @return array<string, mixed>
     */
    public function listSubscriptions(array $options = []): array
    {
        $params = array_merge(['page' => 1, 'page_size' => 20], $options);
        $query  = http_build_query($params);

        return $this->request('GET', "/subscriptions?{$query}");
    }

    /**
     * Get subscription by ID.
     *
     * @param string $subscriptionId Subscription UUID.
     *
     * @return array<string, mixed>
     */
    public function getSubscription(string $subscriptionId): array
    {
        return $this->request('GET', "/subscriptions/{$subscriptionId}");
    }

    /**
     * Cancel a subscription.
     *
     * @param string      $subscriptionId Subscription UUID.
     * @param string|null $reason         Optional cancellation reason.
     *
     * @return array<string, mixed>
     */
    public function cancelSubscription(string $subscriptionId, ?string $reason = null): array
    {
        $body = [];

        if ($reason !== null) {
            $body['reason'] = $reason;
        }

        return $this->request('POST', "/subscriptions/{$subscriptionId}/cancel", $body);
    }

    /**
     * Pause a subscription.
     *
     * @param string $subscriptionId Subscription UUID.
     *
     * @return array<string, mixed>
     */
    public function pauseSubscription(string $subscriptionId): array
    {
        return $this->request('POST', "/subscriptions/{$subscriptionId}/pause");
    }

    /**
     * Resume a paused subscription.
     *
     * @param string $subscriptionId Subscription UUID.
     *
     * @return array<string, mixed>
     */
    public function resumeSubscription(string $subscriptionId): array
    {
        return $this->request('POST', "/subscriptions/{$subscriptionId}/resume");
    }

    // ── Deposit API ────────────────────────────────────────────

    /**
     * Create a deposit user.
     *
     * @param string               $externalUserId External user identifier.
     * @param array<string, mixed> $options        Optional: email, name, metadata, etc.
     *
     * @return array<string, mixed>
     */
    public function createDepositUser(string $externalUserId, array $options = []): array
    {
        $body = array_merge(['external_user_id' => $externalUserId], $options);

        return $this->request('POST', '/deposit-api/users', $body);
    }

    /**
     * Get deposit user by external ID.
     *
     * @param string $externalUserId External user identifier.
     *
     * @return array<string, mixed>
     */
    public function getDepositUser(string $externalUserId): array
    {
        return $this->request('GET', "/deposit-api/users/{$externalUserId}");
    }

    /**
     * List deposit users with optional filters.
     *
     * @param array<string, mixed> $options Optional: page, page_size.
     *
     * @return array<string, mixed>
     */
    public function listDepositUsers(array $options = []): array
    {
        $params = array_merge(['page' => 1, 'page_size' => 20], $options);
        $query  = http_build_query($params);

        return $this->request('GET', "/deposit-api/users?{$query}");
    }

    /**
     * Update a deposit user.
     *
     * @param string               $externalUserId External user identifier.
     * @param array<string, mixed> $data           Fields to update.
     *
     * @return array<string, mixed>
     */
    public function updateDepositUser(string $externalUserId, array $data): array
    {
        return $this->request('PATCH', "/deposit-api/users/{$externalUserId}", $data);
    }

    /**
     * Create a deposit address for a specific blockchain.
     *
     * @param string $externalUserId External user identifier.
     * @param string $blockchain     Chain name.
     *
     * @return array<string, mixed>
     */
    public function createDepositAddress(string $externalUserId, string $blockchain): array
    {
        return $this->request('POST', "/deposit-api/users/{$externalUserId}/addresses", [
            'blockchain' => $blockchain,
        ]);
    }

    /**
     * Create deposit addresses on all supported blockchains.
     *
     * @param string $externalUserId External user identifier.
     *
     * @return array<string, mixed>
     */
    public function createAllDepositAddresses(string $externalUserId): array
    {
        return $this->request('POST', "/deposit-api/users/{$externalUserId}/addresses/all");
    }

    /**
     * List deposit addresses for a user.
     *
     * @param string $externalUserId External user identifier.
     *
     * @return array<string, mixed>
     */
    public function listDepositAddresses(string $externalUserId): array
    {
        return $this->request('GET', "/deposit-api/users/{$externalUserId}/addresses");
    }

    /**
     * List deposits with optional filters.
     *
     * @param array<string, mixed> $options Optional: status, blockchain, page, page_size.
     *
     * @return array<string, mixed>
     */
    public function listDeposits(array $options = []): array
    {
        $params = array_merge(['page' => 1, 'page_size' => 20], $options);
        $query  = http_build_query($params);

        return $this->request('GET', "/deposit-api/deposits?{$query}");
    }

    /**
     * Get deposit by ID.
     *
     * @param string $depositId Deposit UUID.
     *
     * @return array<string, mixed>
     */
    public function getDeposit(string $depositId): array
    {
        return $this->request('GET', "/deposit-api/deposits/{$depositId}");
    }

    /**
     * List deposit balances for a user.
     *
     * @param string $externalUserId External user identifier.
     *
     * @return array<string, mixed>
     */
    public function listDepositBalances(string $externalUserId): array
    {
        return $this->request('GET', "/deposit-api/users/{$externalUserId}/balances");
    }
}
