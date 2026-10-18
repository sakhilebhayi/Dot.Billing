<?php

namespace App\Services\Payments\Paystack;

use App\Exceptions\Payments\PaymentGatewayException;

/**
 * A Paystack transaction as reported by the verify endpoint or a charge.success webhook (the same
 * `data` shape). Amounts are in the currency's subunit (ZAR cents), which is what this app stores.
 */
final readonly class PaystackTransaction
{
    public function __construct(
        public string $id,
        public string $reference,
        public string $status,
        public int $amountCents,
        public string $currency,
        public ?string $channel = null,
        public ?string $paidAt = null,
        public ?string $gatewayResponse = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws PaymentGatewayException when the facts we compare against are missing or malformed
     */
    public static function fromData(array $data): self
    {
        $amount = $data['amount'] ?? null;
        $reference = $data['reference'] ?? null;
        $status = $data['status'] ?? null;
        $currency = $data['currency'] ?? null;

        $amountIsWhole = is_int($amount) || (is_string($amount) && ctype_digit($amount));

        if (! $amountIsWhole || ! is_string($reference) || $reference === '' || ! is_string($status) || ! is_string($currency) || $currency === '') {
            throw PaymentGatewayException::because('invalid_response', 'Paystack sent an answer we could not read.');
        }

        $id = $data['id'] ?? null;

        return new self(
            id: is_int($id) || (is_string($id) && $id !== '') ? (string) $id : $reference,
            reference: $reference,
            status: strtolower($status),
            amountCents: (int) $amount,
            currency: strtoupper($currency),
            channel: is_string($data['channel'] ?? null) ? $data['channel'] : null,
            paidAt: is_string($data['paid_at'] ?? null) ? $data['paid_at'] : null,
            gatewayResponse: is_string($data['gateway_response'] ?? null) ? $data['gateway_response'] : null,
        );
    }

    public function isSuccessful(): bool
    {
        return $this->status === 'success';
    }

    /**
     * The only fields kept from a Paystack transaction. Customer details and the card authorization block
     * are deliberately dropped.
     *
     * @return array<string, mixed>
     */
    public function safePayload(?string $event = null): array
    {
        return array_filter([
            'event' => $event,
            'transaction_id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status,
            'channel' => $this->channel,
            'paid_at' => $this->paidAt,
            'gateway_response' => $this->gatewayResponse,
        ], fn ($v) => $v !== null);
    }
}
