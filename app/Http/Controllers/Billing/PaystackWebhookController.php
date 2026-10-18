<?php

namespace App\Http\Controllers\Billing;

use App\Exceptions\Payments\PaymentGatewayException;
use App\Http\Controllers\Controller;
use App\Models\PaymentAttempt;
use App\Models\PaymentEvent;
use App\Services\Payments\Paystack\PaystackConfig;
use App\Services\Payments\Paystack\PaystackSettlement;
use App\Services\Payments\Paystack\PaystackTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;

/**
 * POST /webhooks/paystack. Server to server: no session, no CSRF, no user.
 *
 * Order matters. The signature (x-paystack-signature = lowercase hex HMAC-SHA512 of the RAW body with the
 * secret key) is verified with hash_equals over the exact bytes BEFORE the body is parsed or trusted. A bad
 * or missing signature is a bare 401. After that:
 *
 *  - not JSON, or no event name: 400;
 *  - charge.success: matched to our attempt by reference and settled (PaystackSettlement);
 *  - refund.processed: recorded for an operator, never applied;
 *  - any other event: acknowledged and ignored;
 *  - every business condition (unknown reference, mismatch, overpayment, replay) is a 200, so Paystack does
 *    not retry forever. The condition is recorded as a held payment event for operators instead.
 */
class PaystackWebhookController extends Controller
{
    public function __invoke(Request $request, PaystackConfig $config, PaystackSettlement $settlement): JsonResponse
    {
        $raw = $request->getContent();

        if (! $this->signatureIsValid($raw, $request->header('x-paystack-signature'), $config)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        try {
            $payload = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return response()->json(['message' => 'Bad request'], 400);
        }

        if (! is_array($payload) || ! is_string($payload['event'] ?? null) || ! is_array($payload['data'] ?? null)) {
            return response()->json(['message' => 'Bad request'], 400);
        }

        $result = match ($payload['event']) {
            'charge.success' => $this->chargeSuccess($payload['data'], $raw, $settlement),
            'refund.processed' => $this->refundProcessed($payload['data'], $raw, $settlement),
            default => 'ignored',
        };

        return response()->json(['status' => $result]);
    }

    private function signatureIsValid(string $raw, mixed $header, PaystackConfig $config): bool
    {
        if (! is_string($header) || trim($header) === '') {
            return false;
        }

        try {
            $key = $config->secretKey();
        } catch (PaymentGatewayException) {
            return false;
        }

        return hash_equals(hash_hmac('sha512', $raw, $key), strtolower(trim($header)));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function chargeSuccess(array $data, string $raw, PaystackSettlement $settlement): string
    {
        try {
            $tx = PaystackTransaction::fromData($data);
        } catch (PaymentGatewayException) {
            $settlement->holdUnknown('payment', $this->fallbackKey($data, $raw), 'unreadable_payload', $this->wholeCents($data['amount'] ?? null), $this->currencyOf($data), [
                'event' => 'charge.success', 'reference' => is_string($data['reference'] ?? null) ? $data['reference'] : null,
            ]);

            return 'held';
        }

        $attempt = PaymentAttempt::withoutGlobalScope('team')
            ->where('provider', PaystackSettlement::PROVIDER)->where('reference', $tx->reference)->first();

        if (! $attempt) {
            $settlement->holdUnknown('payment', $tx->id, 'unknown_reference', $tx->amountCents, $tx->currency, $tx->safePayload('charge.success'));

            return 'held';
        }

        $outcome = $settlement->settle($attempt, $tx, 'charge.success');

        if ($outcome->kind === 'not_successful') {
            $settlement->holdNotSuccessful($attempt, $tx, 'charge.success');

            return 'held';
        }

        return $outcome->kind;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function refundProcessed(array $data, string $raw, PaystackSettlement $settlement): string
    {
        $transaction = $data['transaction'] ?? null;
        $reference = $data['transaction_reference']
            ?? (is_array($transaction) ? ($transaction['reference'] ?? null) : null)
            ?? (is_string($transaction) ? $transaction : null);
        $reference = is_string($reference) && $reference !== '' ? $reference : null;

        $refundRef = $data['refund_reference'] ?? (is_scalar($data['id'] ?? null) ? $data['id'] : null);
        $key = is_scalar($refundRef) && (string) $refundRef !== '' ? (string) $refundRef : ($reference !== null ? $reference.':refund' : 'body:'.sha1($raw));

        $cents = $this->wholeCents($data['amount'] ?? null);
        $currency = $this->currencyOf($data);
        $payload = array_filter([
            'event' => 'refund.processed',
            'status' => is_string($data['status'] ?? null) ? $data['status'] : null,
            'reference' => $reference,
            'refund_reference' => is_scalar($refundRef) ? (string) $refundRef : null,
        ], fn ($v) => $v !== null);

        $attempt = $reference === null ? null : PaymentAttempt::withoutGlobalScope('team')
            ->where('provider', PaystackSettlement::PROVIDER)->where('reference', $reference)->first();

        if (! $attempt) {
            $settlement->holdUnknown('refund', $key, 'unknown_reference', $cents, $currency, $payload, PaymentEvent::NEEDS_REVIEW);

            return 'held';
        }

        $settlement->holdRefund($attempt, $key, $cents, $currency, $payload);

        return 'held';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function fallbackKey(array $data, string $raw): string
    {
        foreach (['id', 'reference'] as $field) {
            if (is_scalar($data[$field] ?? null) && (string) $data[$field] !== '') {
                return (string) $data[$field];
            }
        }

        return 'body:'.sha1($raw);
    }

    private function wholeCents(mixed $amount): int
    {
        return is_int($amount) || (is_string($amount) && ctype_digit($amount) && strlen($amount) < 16) ? (int) $amount : 0;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function currencyOf(array $data): string
    {
        $currency = $data['currency'] ?? null;

        return is_string($currency) && preg_match('/^[A-Za-z]{3}$/', $currency) ? strtoupper($currency) : 'XXX';
    }
}
