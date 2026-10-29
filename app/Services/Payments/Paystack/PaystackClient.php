<?php

namespace App\Services\Payments\Paystack;

use App\Exceptions\Payments\PaymentGatewayException;
use App\Models\PaymentAttempt;
use App\Models\TicketPayment;
use App\Support\Redacted;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The only code that talks to api.paystack.co. Server to server, bearer auth with the secret key, a 10
 * second timeout, no redirects followed, no retries, base URL pinned to https. It returns typed results and
 * turns every failure into a PaymentGatewayException whose message is safe to show a person.
 *
 * Logging: a failure logs the endpoint name, the HTTP status and our own reference. It never logs the key,
 * the request body, the response body, the checkout address or the access code.
 */
class PaystackClient
{
    public function __construct(private PaystackConfig $config) {}

    /**
     * POST /transaction/initialize for an attempt. The amount, currency and reference are the attempt's.
     *
     * @throws PaymentGatewayException
     */
    public function initializeTransaction(PaymentAttempt $attempt, string $payerEmail): PaystackInitialization
    {
        $invoice = $attempt->invoice;

        $data = $this->send('initialize', $attempt->reference, fn (PendingRequest $http) => $http->post('/transaction/initialize', [
            'email' => $payerEmail,
            'amount' => $attempt->amount_cents,
            'currency' => $attempt->currency,
            'reference' => $attempt->reference,
            'callback_url' => route('paystack.callback', ['attempt' => $attempt->uuid]),
            'metadata' => json_encode([
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'team_id' => $invoice->team_id,
                'attempt' => $attempt->uuid,
            ], JSON_THROW_ON_ERROR),
        ]));

        return $this->checkoutFrom($data, $attempt->reference);
    }

    /**
     * POST /transaction/initialize for a seminar ticket, split to the host's subaccount. The ticket's
     * amount, currency and reference are sent as they are stored. `bearer` = subaccount puts Paystack's fee on
     * the host, and a FLAT `transaction_charge` makes InfoDot's share exactly the stored platform fee (a
     * percentage would be recomputed by Paystack). The return address, when there is one, becomes the
     * callback_url; it is only where the attendee's browser lands and never marks anything paid.
     *
     * @throws PaymentGatewayException
     */
    public function initializeTicketTransaction(TicketPayment $ticket, string $subaccountCode): PaystackInitialization
    {
        $body = [
            'email' => $ticket->buyer_email,
            'amount' => $ticket->amount_cents,
            'currency' => $ticket->currency,
            'reference' => $ticket->paystack_reference,
            'subaccount' => $subaccountCode,
            'bearer' => 'subaccount',
            'transaction_charge' => $ticket->platform_fee_cents,
            'metadata' => json_encode([
                'kind' => 'seminar_ticket',
                'ticket_ref' => $ticket->ticket_ref,
                'description' => $ticket->description,
            ], JSON_THROW_ON_ERROR),
        ];

        if ($ticket->return_url !== null) {
            $body['callback_url'] = $ticket->return_url;
        }

        $data = $this->send('initialize', $ticket->paystack_reference, fn (PendingRequest $http) => $http->post('/transaction/initialize', $body));

        return $this->checkoutFrom($data, $ticket->paystack_reference);
    }

    /**
     * GET /bank for South Africa (ZAR): the banks a host can be paid into.
     *
     * @return list<array{name: string, code: string}>
     *
     * @throws PaymentGatewayException
     */
    public function listBanks(): array
    {
        $data = $this->send('banks', 'bank-list', fn (PendingRequest $http) => $http->get('/bank', [
            'country' => 'south africa', 'currency' => 'ZAR', 'perPage' => 100,
        ]));

        $banks = [];

        foreach ($data as $bank) {
            if (is_array($bank) && is_string($bank['name'] ?? null) && $bank['name'] !== '' && (is_string($bank['code'] ?? null) || is_int($bank['code'] ?? null)) && (string) $bank['code'] !== '' && ($bank['active'] ?? true) !== false) {
                $banks[] = ['name' => $bank['name'], 'code' => (string) $bank['code']];
            }
        }

        usort($banks, fn (array $a, array $b) => strcasecmp($a['name'], $b['name']));

        return $banks;
    }

    /**
     * POST /subaccount. Returns ONLY Paystack's subaccount code. Paystack's answer echoes the account number
     * back; it is never read, kept or logged here. $label is our own host reference, used only in log lines.
     *
     * @throws PaymentGatewayException
     */
    public function createSubaccount(string $label, string $businessName, string $bankCode, Redacted $accountNumber, int $percentageCharge): string
    {
        $data = $this->send('subaccount_create', $label, fn (PendingRequest $http) => $http->post('/subaccount', $this->subaccountBody($businessName, $bankCode, $accountNumber, $percentageCharge)));

        return $this->subaccountCodeFrom($data, 'subaccount_create', $label);
    }

    /**
     * PUT /subaccount/{code}: change the details of an existing subaccount instead of creating a second one.
     *
     * @throws PaymentGatewayException
     */
    public function updateSubaccount(string $label, string $subaccountCode, string $businessName, string $bankCode, Redacted $accountNumber, int $percentageCharge): string
    {
        $data = $this->send('subaccount_update', $label, fn (PendingRequest $http) => $http->put('/subaccount/'.rawurlencode($subaccountCode), $this->subaccountBody($businessName, $bankCode, $accountNumber, $percentageCharge)));

        return $this->subaccountCodeFrom($data, 'subaccount_update', $label) === $subaccountCode
            ? $subaccountCode
            : $this->invalid('subaccount_update', $label);
    }

    /**
     * POST /refund. With no amount Paystack refunds the transaction in full; with an amount (and currency) it
     * refunds that much. Returns Paystack's refund status ("pending", "processing", ...) and its refund id when
     * it gave one. The ticket is NOT refunded by this call; only the refund.processed webhook says so.
     *
     * @return array{status: string, id: ?string}
     *
     * @throws PaymentGatewayException
     */
    public function refundTransaction(string $reference, ?int $amountCents = null, ?string $currency = null): array
    {
        $body = ['transaction' => $reference];

        if ($amountCents !== null) {
            $body['amount'] = $amountCents;
            $body['currency'] = $currency;
        }

        $data = $this->send('refund', $reference, fn (PendingRequest $http) => $http->post('/refund', array_filter($body, fn ($v) => $v !== null)));

        if (! is_string($data['status'] ?? null) || $data['status'] === '') {
            $this->invalid('refund', $reference);
        }

        $id = $data['id'] ?? $data['refund_reference'] ?? null;

        return ['status' => strtolower($data['status']), 'id' => is_scalar($id) && (string) $id !== '' ? (string) $id : null];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function checkoutFrom(array $data, string $reference): PaystackInitialization
    {
        $url = $data['authorization_url'] ?? null;

        if (! is_string($url) || ! $this->isPaystackCheckoutUrl($url) || ! is_string($data['access_code'] ?? null) || ($data['reference'] ?? null) !== $reference) {
            Log::warning('paystack.invalid_response', ['endpoint' => 'initialize', 'reference' => $reference]);

            throw PaymentGatewayException::because('invalid_response', 'Paystack sent an answer we could not use. Nothing was charged; please try again.');
        }

        return new PaystackInitialization($url, $reference);
    }

    /**
     * @return array<string, mixed>
     */
    private function subaccountBody(string $businessName, string $bankCode, Redacted $accountNumber, int $percentageCharge): array
    {
        return [
            'business_name' => $businessName,
            'settlement_bank' => $bankCode,
            'account_number' => $accountNumber->reveal(),
            'percentage_charge' => $percentageCharge,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function subaccountCodeFrom(array $data, string $endpoint, string $label): string
    {
        $code = $data['subaccount_code'] ?? null;

        return is_string($code) && $code !== '' ? $code : $this->invalid($endpoint, $label);
    }

    private function invalid(string $endpoint, string $label): never
    {
        Log::warning('paystack.invalid_response', ['endpoint' => $endpoint, 'reference' => $label]);

        throw PaymentGatewayException::because('invalid_response', 'Paystack sent an answer we could not read.');
    }

    /**
     * GET /transaction/verify/{reference}.
     *
     * @throws PaymentGatewayException
     */
    public function verifyTransaction(string $reference): PaystackTransaction
    {
        $data = $this->send('verify', $reference, fn (PendingRequest $http) => $http->get('/transaction/verify/'.rawurlencode($reference)));

        try {
            return PaystackTransaction::fromData($data);
        } catch (PaymentGatewayException $e) {
            Log::warning('paystack.invalid_response', ['endpoint' => 'verify', 'reference' => $reference]);

            throw $e;
        }
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     * @return array<string, mixed> Paystack's `data` object
     */
    private function send(string $endpoint, string $reference, callable $call): array
    {
        $http = Http::baseUrl($this->config->baseUrl())
            ->withToken($this->config->secretKey())
            ->acceptJson()
            ->asJson()
            ->connectTimeout($this->config->timeout())
            ->timeout($this->config->timeout())
            ->withoutRedirecting();

        try {
            $response = $call($http);
        } catch (ConnectionException) {
            Log::warning('paystack.unreachable', ['endpoint' => $endpoint, 'reference' => $reference]);

            throw PaymentGatewayException::because('unavailable', 'Paystack could not be reached just now. Nothing was charged; please try again in a moment.');
        } catch (RequestException) {
            Log::warning('paystack.request_exception', ['endpoint' => $endpoint, 'reference' => $reference]);

            throw PaymentGatewayException::because('unavailable', 'Paystack could not be reached just now. Nothing was charged; please try again in a moment.');
        }

        $status = $response->status();

        if ($status >= 500 || $status === 429) {
            Log::warning('paystack.unavailable', ['endpoint' => $endpoint, 'http_status' => $status, 'reference' => $reference]);

            throw PaymentGatewayException::because('unavailable', 'Paystack is having trouble just now. Nothing was charged; please try again in a moment.');
        }

        if ($status >= 300) {
            Log::warning('paystack.rejected', ['endpoint' => $endpoint, 'http_status' => $status, 'reference' => $reference]);

            throw PaymentGatewayException::because($status >= 400 ? 'rejected' : 'invalid_response', 'Paystack did not accept that request. Nothing was charged.');
        }

        $json = $response->json();

        if (! is_array($json) || ($json['status'] ?? null) !== true || ! is_array($json['data'] ?? null)) {
            Log::warning('paystack.invalid_response', ['endpoint' => $endpoint, 'http_status' => $status, 'reference' => $reference]);

            throw PaymentGatewayException::because('invalid_response', 'Paystack sent an answer we could not read.');
        }

        return $json['data'];
    }

    /**
     * Where the payer is sent. Only Paystack's own checkout hosts, so a tampered base URL or response
     * cannot turn "Pay now" into a redirect to someone else's page.
     */
    private function isPaystackCheckoutUrl(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));

        return ($parts['scheme'] ?? '') === 'https'
            && ($host === 'paystack.com' || str_ends_with($host, '.paystack.com') || $host === 'paystack.co' || str_ends_with($host, '.paystack.co'));
    }
}
