<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dunning cases get a real grace state and stop being duplicable.
 *
 * - grace_until: "Extend" used to move the invoice's due date and mark the case "extended"; the invoice
 *   stayed open and nothing remembered when the extension ended. A case in status "grace" now carries
 *   its end date, and the scanner reopens it only after that date.
 * - one overdue case per invoice, one case per failed payment. The scanner's check-then-insert raced; the
 *   database now refuses the second row: unique(payment_id) everywhere (NULLs are distinct), and a
 *   partial unique index on invoice_id for reason = invoice_overdue on sqlite and PostgreSQL (MySQL has no
 *   partial indexes; there the scanner's check is the only guard).
 *
 * LEGACY DATA: duplicates that the race already created are kept, never deleted. The older row keeps its
 * link; later duplicates lose their invoice_id (overdue cases) or payment_id (failed-payment cases) and get
 * a note saying what they duplicated. Statuses are not touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dunning_cases', function (Blueprint $table) {
            $table->timestamp('grace_until')->nullable();
        });

        $this->unlinkDuplicates();

        Schema::table('dunning_cases', function (Blueprint $table) {
            $table->unique('payment_id');
            $table->index(['team_id', 'status']);
            $table->index('invoice_id');
        });

        if (in_array(DB::connection()->getDriverName(), ['sqlite', 'pgsql'], true)) {
            DB::statement("CREATE UNIQUE INDEX dunning_cases_one_overdue_per_invoice ON dunning_cases (invoice_id) WHERE reason = 'invoice_overdue' AND invoice_id IS NOT NULL");
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS dunning_cases_one_overdue_per_invoice');

        Schema::table('dunning_cases', function (Blueprint $table) {
            $table->dropUnique(['payment_id']);
            $table->dropIndex(['team_id', 'status']);
            $table->dropIndex(['invoice_id']);
        });

        Schema::table('dunning_cases', function (Blueprint $table) {
            $table->dropColumn('grace_until');
        });
    }

    private function unlinkDuplicates(): void
    {
        $now = now()->toDateTimeString();

        $overdueInvoiceIds = DB::table('dunning_cases')
            ->where('reason', 'invoice_overdue')->whereNotNull('invoice_id')
            ->groupBy('invoice_id')->havingRaw('count(*) > 1')->pluck('invoice_id');

        foreach ($overdueInvoiceIds as $invoiceId) {
            $cases = DB::table('dunning_cases')->where('reason', 'invoice_overdue')->where('invoice_id', $invoiceId)->orderBy('id')->get();
            $keep = $cases->shift();

            foreach ($cases as $duplicate) {
                DB::table('dunning_cases')->where('id', $duplicate->id)->update([
                    'invoice_id' => null,
                    'resolution_notes' => $this->note($duplicate->resolution_notes, "Legacy duplicate of case #{$keep->id} for invoice #{$invoiceId}; unlinked so the invoice has one overdue case."),
                    'updated_at' => $now,
                ]);
            }
        }

        $paymentIds = DB::table('dunning_cases')->whereNotNull('payment_id')->groupBy('payment_id')->havingRaw('count(*) > 1')->pluck('payment_id');

        foreach ($paymentIds as $paymentId) {
            $cases = DB::table('dunning_cases')->where('payment_id', $paymentId)->orderBy('id')->get();
            $keep = $cases->shift();

            foreach ($cases as $duplicate) {
                DB::table('dunning_cases')->where('id', $duplicate->id)->update([
                    'payment_id' => null,
                    'resolution_notes' => $this->note($duplicate->resolution_notes, "Legacy duplicate of case #{$keep->id} for payment #{$paymentId}; unlinked so the payment has one case."),
                    'updated_at' => $now,
                ]);
            }
        }
    }

    private function note(?string $existing, string $added): string
    {
        return $existing ? $existing.' | '.$added : $added;
    }
};
