<?php

namespace App\Support;

/**
 * Removes anything credential-shaped from a payload before it is stored. By key name, at any depth:
 * secrets, tokens, passwords, authorization headers, API keys, card numbers and CVVs. It is a net, not
 * a promise that arbitrary free text is clean: adapters should still send only what they need.
 */
final class PayloadScrubber
{
    private const SENSITIVE = '/secret|token|passw|authorization|api[_-]?key|private[_-]?key|signature|card|cvv|cvc|\bpan\b|iban|account[_-]?number/i';

    private const MAX_BYTES = 60000;

    /**
     * @param  array<mixed>  $payload
     * @return array<mixed>
     */
    public static function scrub(array $payload): array
    {
        $clean = self::walk($payload);

        if (strlen((string) json_encode($clean)) > self::MAX_BYTES) {
            return ['truncated' => true, 'reason' => 'payload larger than '.self::MAX_BYTES.' bytes was not stored'];
        }

        return $clean;
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private static function walk(array $value): array
    {
        $out = [];

        foreach ($value as $key => $item) {
            if (is_string($key) && preg_match(self::SENSITIVE, $key)) {
                $out[$key] = '[redacted]';
            } elseif (is_array($item)) {
                $out[$key] = self::walk($item);
            } else {
                $out[$key] = $item;
            }
        }

        return $out;
    }
}
