<?php

declare(strict_types=1);

namespace FintraPay;

/**
 * Webhook signature verification helper.
 *
 * FintraPay signs every webhook payload with HMAC-SHA256 using your
 * webhook secret. The signature is sent in the X-FintraPay-Signature header.
 * Always verify the signature before processing the payload.
 *
 * Usage (plain PHP):
 *
 *     $rawBody   = file_get_contents('php://input');
 *     $signature = $_SERVER['HTTP_X_FINTRAPAY_SIGNATURE'] ?? '';
 *
 *     if (!\FintraPay\Webhook::verifySignature($rawBody, $signature, $webhookSecret)) {
 *         http_response_code(401);
 *         exit('Invalid signature');
 *     }
 *
 *     $data = json_decode($rawBody, true);
 *     // process webhook event...
 *
 * Usage (Laravel):
 *
 *     use Illuminate\Http\Request;
 *     use FintraPay\Webhook;
 *
 *     Route::post('/webhook', function (Request $request) {
 *         $rawBody   = $request->getContent();
 *         $signature = $request->header('X-FintraPay-Signature', '');
 *
 *         if (!Webhook::verifySignature($rawBody, $signature, config('services.fintrapay.webhook_secret'))) {
 *             return response('Invalid signature', 401);
 *         }
 *
 *         $data = $request->json()->all();
 *         // process webhook event...
 *
 *         return response('OK', 200);
 *     });
 *
 * Usage (Symfony):
 *
 *     use Symfony\Component\HttpFoundation\Request;
 *     use Symfony\Component\HttpFoundation\Response;
 *     use FintraPay\Webhook;
 *
 *     public function webhookAction(Request $request): Response
 *     {
 *         $rawBody   = $request->getContent();
 *         $signature = $request->headers->get('X-FintraPay-Signature', '');
 *
 *         if (!Webhook::verifySignature($rawBody, $signature, $this->webhookSecret)) {
 *             return new Response('Invalid signature', 401);
 *         }
 *
 *         $data = json_decode($rawBody, true);
 *         // process webhook event...
 *
 *         return new Response('OK', 200);
 *     }
 */
final class Webhook
{
    /**
     * Verify an FintraPay webhook signature.
     *
     * @param string $rawBody       The raw request body (do NOT json_decode first).
     * @param string $signature     The X-FintraPay-Signature header value.
     * @param string $webhookSecret Your webhook secret from the FintraPay dashboard.
     *
     * @return bool True if the signature is valid.
     */
    /**
     * Verify an FintraPay v2 webhook signature.
     *
     * The v2 envelope signs (timestamp + "\n" + rawBody) with HMAC-SHA256 hex.
     * Pass the X-FintraPay-Timestamp header value as $timestamp. Empty string
     * means "verify legacy v1 raw-body signing" (discouraged).
     *
     * Also rejects deliveries older than $maxAgeSeconds (default 5 minutes) to
     * defeat replay attacks.
     */
    public static function verifySignature(
        string $rawBody,
        string $signature,
        string $webhookSecret,
        string $timestamp = '',
        int $maxAgeSeconds = 300
    ): bool {
        if ($rawBody === '' || $signature === '' || $webhookSecret === '') {
            return false;
        }

        $payload = $rawBody;
        if ($timestamp !== '') {
            $ts = strtotime($timestamp);
            if ($ts === false) {
                return false;
            }
            if (abs(time() - $ts) > $maxAgeSeconds) {
                return false;
            }
            $payload = $timestamp . "\n" . $rawBody;
        }

        $expected = hash_hmac('sha256', $payload, $webhookSecret);

        return hash_equals($expected, $signature);
    }

    /** @codeCoverageIgnore */
    private function __construct()
    {
        // Prevent instantiation.
    }
}
