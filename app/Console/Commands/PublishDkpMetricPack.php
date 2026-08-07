<?php

namespace App\Console\Commands;

use App\Models\BillingInvoice;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * The one, hand-run publish script for Dot.Billing's first real Dot
 * Knowledge Protocol pack — deliberately not a scheduled job or pipeline.
 * See Dot.Brain os/05-Knowledge-Protocol.md §5 for why this is sized the
 * way it is, and os/19-Knowledge-Packs.md §2.1 for the payload this
 * follows the shape of.
 *
 * It does not transmit anywhere — DKP's transport layer is unbuilt
 * ecosystem-wide — it signs and writes one JSON file for a human to read.
 */
class PublishDkpMetricPack extends Command
{
    protected $signature = 'dkp:publish-metric
        {--contributor-email= : Email of the human publishing this pack}
        {--contributor-name= : Display name of the human publishing this pack}';

    protected $description = 'Sign and write one real DKP metric pack for billing.invoice_payment_success_rate';

    public function handle(): int
    {
        $keyPath = config('dkp.signing_key_path');

        if (! File::exists($keyPath)) {
            $this->error("No signing key at {$keyPath}. See storage/app/private/README.md.");

            return self::FAILURE;
        }

        $seed = base64_decode(trim(File::get($keyPath)), strict: true);

        if ($seed === false || strlen($seed) !== SODIUM_CRYPTO_SIGN_SEEDBYTES) {
            $this->error('Signing key is not a valid 32-byte base64 Ed25519 seed.');

            return self::FAILURE;
        }

        $keypair = sodium_crypto_sign_seed_keypair($seed);
        $secretKey = sodium_crypto_sign_secretkey($keypair);

        $body = $this->computeMetric();

        $packId = 'dkp:'.config('dkp.platform').':'.(string) Str::uuid();
        $createdAt = now()->toIso8601ZuluString();

        $pack = [
            'dkp_version' => config('dkp.dkp_version'),
            'pack_id' => $packId,
            'pack_version' => '1.0.0',
            'platform' => config('dkp.platform'),
            'title' => 'Invoice payment success rate — definition and current observations',
            'summary' => 'The exact computation rule for billing.invoice_payment_success_rate, plus '
                .'whatever real observations exist in this database at publish time. This is '
                ."Dot.Billing's first published Knowledge Pack — see body.observations for what is "
                .'actually measured versus definitional.',
            'created_at' => $createdAt,
            'contributors' => [
                [
                    'id' => $this->option('contributor-email') ?: 'unknown@dot-billing',
                    'kind' => 'human',
                    'display_name' => $this->option('contributor-name') ?: 'Dot.Billing Platform Lead',
                    'key_id' => config('dkp.key_id'),
                ],
            ],
            'payloads' => [
                [
                    'payload_type' => 'metric',
                    'body' => $body,
                ],
            ],
            'provenance' => [
                'sources' => [
                    [
                        'kind' => 'system',
                        'uri' => 'dot-billing://billing_invoices',
                        'observed_at' => $createdAt,
                    ],
                ],
                'transformations' => [
                    [
                        'step' => 'aggregate-invoice-payment-success-rate',
                        'tool' => 'App\\Console\\Commands\\PublishDkpMetricPack',
                        'tool_version' => '1.0.0',
                        'actor' => config('dkp.platform'),
                    ],
                ],
                'published_by' => config('dkp.platform'),
            ],
            'confidence' => empty($body['observations']) ? 0.30 : 0.70,
            'signatures' => [],
        ];

        $canonical = $this->canonicalize($pack);
        $signature = sodium_crypto_sign_detached($canonical, $secretKey);

        $pack['signatures'] = [
            [
                'key_id' => config('dkp.key_id'),
                'algorithm' => 'ed25519-jcs',
                'signed_at' => $createdAt,
                'value' => base64_encode($signature),
            ],
        ];

        $outputDir = config('dkp.output_path');
        File::ensureDirectoryExists($outputDir);
        $outputPath = $outputDir.'/'.Str::after($packId, ':'.config('dkp.platform').':').'.json';
        File::put($outputPath, json_encode($pack, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        $this->info("Pack written to {$outputPath}");
        $this->line("pack_id: {$packId}");
        $this->line('Signature verifies: '.($this->verify($pack, $keypair) ? 'yes' : 'NO — DO NOT PUBLISH'));

        return self::SUCCESS;
    }

    /**
     * billing.invoice_payment_success_rate: invoices marked paid within
     * their due window, divided by invoices issued in the same window,
     * per calendar month. Reads real BillingInvoice rows — if none exist
     * yet in this database, observations is honestly empty rather than
     * fabricated, per the ecosystem's no-placeholder-content rule.
     */
    private function computeMetric(): array
    {
        $body = [
            'metric_name' => 'billing.invoice_payment_success_rate',
            'domain' => 'payments',
            'definition' => 'Invoices with status=paid and paid_at <= due_date, divided by all '
                .'invoices with due_date in the same calendar month, computed monthly.',
            'unit' => 'ratio',
            'direction_of_good' => 'up',
            'dimensions' => ['currency'],
        ];

        $months = BillingInvoice::query()
            ->selectRaw("date_trunc('month', due_date) as month")
            ->whereNotNull('due_date')
            ->distinct()
            ->pluck('month');

        $observations = [];

        foreach ($months as $rawMonth) {
            $month = Carbon::parse($rawMonth);

            $issued = BillingInvoice::query()
                ->whereBetween('due_date', [$month, (clone $month)->addMonthNoOverflow()])
                ->count();

            if ($issued === 0) {
                continue;
            }

            $paidOnTime = BillingInvoice::query()
                ->whereBetween('due_date', [$month, (clone $month)->addMonthNoOverflow()])
                ->where('status', 'paid')
                ->whereColumn('paid_at', '<=', 'due_date')
                ->count();

            $observations[] = [
                'timestamp' => $month->toIso8601ZuluString(),
                'value' => round($paidOnTime / $issued, 4),
                'sample_size' => $issued,
            ];
        }

        if (! empty($observations)) {
            $body['observations'] = $observations;
        }

        return $body;
    }

    /**
     * RFC 8785-shaped canonicalization (sorted keys, no insignificant
     * whitespace) of everything except the not-yet-populated signatures
     * array, which is what gets signed.
     */
    private function canonicalize(array $pack): string
    {
        $signable = $pack;
        unset($signable['signatures']);

        return json_encode($this->sortKeysRecursive($signable), JSON_UNESCAPED_SLASHES);
    }

    private function sortKeysRecursive(array $value): array
    {
        $isList = array_is_list($value);

        if (! $isList) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortKeysRecursive($item);
            }
        }

        return $value;
    }

    private function verify(array $pack, string $keypair): bool
    {
        $publicKey = sodium_crypto_sign_publickey($keypair);
        $signature = base64_decode($pack['signatures'][0]['value']);
        $canonical = $this->canonicalize($pack);

        return sodium_crypto_sign_verify_detached($signature, $canonical, $publicKey);
    }
}
