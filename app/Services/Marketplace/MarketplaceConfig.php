<?php

namespace App\Services\Marketplace;

/**
 * The one place that reads billing.marketplace. Read on every call (nothing cached) so a changed environment
 * takes effect and tests can switch values.
 *
 * isValid() is conservative: a platform percent that is not a whole number 0 to 50, bounds that make no sense,
 * or an empty currency list make the marketplace refuse to take money (503) rather than guess.
 */
class MarketplaceConfig
{
    public const CALLER = 'infodot';

    public function enabled(): bool
    {
        return (bool) config('billing.marketplace.enabled', false);
    }

    /** InfoDot's share as a whole percent, or null when the configured value is not an integer 0 to 50. */
    public function platformPercent(): ?int
    {
        $raw = config('billing.marketplace.platform_percent');

        if (is_string($raw) && preg_match('/^\d{1,2}$/', trim($raw))) {
            $raw = (int) trim($raw);
        }

        return is_int($raw) && $raw >= 0 && $raw <= 50 ? $raw : null;
    }

    public function isValid(): bool
    {
        return $this->platformPercent() !== null
            && $this->currencies() !== []
            && $this->minAmountCents() >= 1
            && $this->maxAmountCents() >= $this->minAmountCents();
    }

    /** @return list<string> */
    public function currencies(): array
    {
        return array_values(array_unique(array_map('strtoupper', array_filter((array) config('billing.marketplace.currencies', []), 'is_string'))));
    }

    public function minAmountCents(): int
    {
        return (int) config('billing.marketplace.min_amount_cents', 500);
    }

    public function maxAmountCents(): int
    {
        return (int) config('billing.marketplace.max_amount_cents', 5000000);
    }

    /** InfoDot's share of a price: amount x percent / 100, rounded half up to the cent, in integer arithmetic. */
    public function platformFeeCents(int $amountCents): int
    {
        return intdiv($amountCents * (int) $this->platformPercent() + 50, 100);
    }

    /** @return list<string> */
    public function returnHosts(): array
    {
        return array_values(array_unique(array_map('strtolower', array_filter((array) config('billing.marketplace.return_hosts', []), 'is_string'))));
    }

    /**
     * May the attendee be sent back to this address? https only, no credentials in it, no control or
     * backslash characters (browsers read "\" as "/"), and the host must be listed. An empty list rejects all.
     */
    public function returnUrlAllowed(string $url): bool
    {
        if ($url === '' || strlen($url) > 300 || preg_match('/[^\x21-\x7e]/', $url) || str_contains($url, '\\')) {
            return false;
        }

        $parts = parse_url($url);

        if ($parts === false || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));

        return $host !== '' && in_array($host, $this->returnHosts(), true);
    }

    /** The callback address, only when it is https. */
    public function callbackUrl(): ?string
    {
        $url = trim((string) config('billing.marketplace.callback_url'));

        return str_starts_with(strtolower($url), 'https://') && filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : null;
    }

    public function callbackSecret(): string
    {
        return trim((string) config('billing.marketplace.callback_secret'));
    }

    public function callbackConfigured(): bool
    {
        return $this->callbackUrl() !== null && $this->callbackSecret() !== '';
    }

    public function callbackTimeout(): int
    {
        return max(1, (int) config('billing.marketplace.callback_timeout', 5));
    }

    public function banksCacheSeconds(): int
    {
        return max(60, (int) config('billing.marketplace.banks_cache_seconds', 21600));
    }
}
