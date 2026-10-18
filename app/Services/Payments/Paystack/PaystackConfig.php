<?php

namespace App\Services\Payments\Paystack;

use App\Exceptions\Payments\PaymentGatewayException;
use Illuminate\Support\Facades\Log;

/**
 * The one place that decides whether Paystack may be used, and with which key. It reads config on every
 * call (nothing is cached) so a changed environment takes effect and tests can switch keys.
 *
 * The test-mode guard: a key starting "sk_test_" is test mode. A key starting "sk_live_" is REFUSED unless
 * paystack.allow_live is also true, and a key that is neither is refused as unrecognised. A refused key is
 * never returned by secretKey(), so it can never be sent or used to verify a webhook. Nothing here ever
 * writes the key anywhere.
 */
class PaystackConfig
{
    public const TEST = 'test';

    public const LIVE = 'live';

    public function isConfigured(): bool
    {
        return $this->rawKey() !== '';
    }

    /** True when a key is set but may not be used (live without the second switch, or unrecognised). */
    public function isRefused(): bool
    {
        return $this->isConfigured() && $this->refusal() !== null;
    }

    public function canTakePayments(): bool
    {
        return $this->isConfigured() && $this->refusal() === null && $this->httpsBaseUrl() !== null;
    }

    /** "test", "live", or null when payments are off (no key, or a refused one). */
    public function mode(): ?string
    {
        if (! $this->isConfigured() || $this->refusal() !== null) {
            return null;
        }

        return str_starts_with($this->rawKey(), 'sk_test_') ? self::TEST : self::LIVE;
    }

    public function isTestMode(): bool
    {
        return $this->mode() === self::TEST;
    }

    /**
     * What to tell a person who is allowed to know why online payment is off; null when it is on.
     */
    public function notice(): ?string
    {
        if (! $this->isConfigured()) {
            return 'Online payment is not set up on this environment. Record a manual payment instead.';
        }

        return match ($this->refusal()) {
            'live_key_refused' => 'Online payment is switched off: a live Paystack key is configured but PAYSTACK_ALLOW_LIVE is not true. Use a test key, or set both deliberately.',
            'unrecognised_key' => 'Online payment is switched off: the configured Paystack key is not a recognised secret key (sk_test_...).',
            default => $this->httpsBaseUrl() === null ? 'Online payment is switched off: the Paystack base URL must be https.' : null,
        };
    }

    /**
     * @throws PaymentGatewayException when there is no key, or it is refused (the refusal is logged, never the key)
     */
    public function secretKey(): string
    {
        if (! $this->isConfigured()) {
            throw PaymentGatewayException::because('not_configured', 'Online payment is not set up.');
        }

        if (($refusal = $this->refusal()) !== null) {
            Log::critical('paystack.key_refused', ['reason' => $refusal, 'allow_live' => $this->allowLive()]);

            throw PaymentGatewayException::because($refusal === 'live_key_refused' ? 'live_key_refused' : 'not_configured', 'Online payment is switched off on this environment.');
        }

        return $this->rawKey();
    }

    public function publicKey(): ?string
    {
        $key = trim((string) config('paystack.public_key'));

        return $key === '' ? null : $key;
    }

    /**
     * @throws PaymentGatewayException when the base URL is not https
     */
    public function baseUrl(): string
    {
        return $this->httpsBaseUrl()
            ?? throw PaymentGatewayException::because('not_configured', 'Online payment is switched off: the gateway address is not https.');
    }

    public function timeout(): int
    {
        return max(1, (int) config('paystack.timeout', 10));
    }

    public function attemptTtlMinutes(): int
    {
        return max(1, (int) config('paystack.attempt_ttl_minutes', 30));
    }

    private function rawKey(): string
    {
        return trim((string) config('paystack.secret_key'));
    }

    private function allowLive(): bool
    {
        return (bool) config('paystack.allow_live');
    }

    private function refusal(): ?string
    {
        $key = $this->rawKey();

        return match (true) {
            str_starts_with($key, 'sk_test_') => null,
            str_starts_with($key, 'sk_live_') => $this->allowLive() ? null : 'live_key_refused',
            default => 'unrecognised_key',
        };
    }

    private function httpsBaseUrl(): ?string
    {
        $url = rtrim(trim((string) config('paystack.base_url')), '/');

        return str_starts_with(strtolower($url), 'https://') && filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : null;
    }
}
