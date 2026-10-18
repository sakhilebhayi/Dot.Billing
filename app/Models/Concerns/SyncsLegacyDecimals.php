<?php

namespace App\Models\Concerns;

use App\Support\Money;

/**
 * Money lives in integer *_cents columns; the original decimal(10,2) columns are kept as a mirror
 * (rollback safety, old readers). Declare the pairs on the model:
 *
 *     protected array $legacyDecimals = ['total' => 'total_cents'];   // decimal column => cents column
 *
 * On save: if the cents column was written it is the truth and the decimal is rewritten from it; if
 * only the decimal was written (old code) the cents are derived from it by string arithmetic. If both
 * were written, cents win. New code writes cents and reads cents.
 */
trait SyncsLegacyDecimals
{
    protected static function bootSyncsLegacyDecimals(): void
    {
        static::saving(function ($model): void {
            foreach ($model->legacyDecimals as $decimal => $cents) {
                if ($model->isDirty($cents)) {
                    $model->setAttribute($decimal, Money::centsToDecimal((int) $model->getAttribute($cents)));
                } elseif ($model->isDirty($decimal)) {
                    $model->setAttribute($cents, Money::decimalToCents($model->getRawOriginalOrAttribute($decimal)));
                }
            }
        });
    }

    /** The decimal as the caller set it, before any cast, so 0.29 never takes a float round trip. */
    protected function getRawOriginalOrAttribute(string $key): string|int|float|null
    {
        $value = $this->attributes[$key] ?? null;

        return is_string($value) || is_int($value) || is_float($value) ? $value : null;
    }
}
