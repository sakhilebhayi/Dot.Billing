<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Subscriptions become something the app can run: trial length on the plan, a scheduled-cancel flag,
 * an end date, a plan change queued for renewal, and a database-level rule that a team has at most
 * one live subscription.
 *
 * LEGACY DATA: nothing used to stop a team holding several live subscriptions, so before the unique
 * index can exist, any team with more than one live subscription keeps the newest (highest id) and the
 * older ones are marked canceled, each with an audit row. Rows are never deleted.
 *
 * The one-live-subscription rule is a partial unique index: sqlite and PostgreSQL only. MySQL and
 * MariaDB have no partial indexes, so there the rule is enforced by SubscriptionService alone.
 */
return new class extends Migration
{
    private const LIVE = "('active','trialing','past_due')";

    public function up(): void
    {
        Schema::table('billing_plans', function (Blueprint $table) {
            if (! Schema::hasColumn('billing_plans', 'trial_days')) {
                $table->unsignedSmallInteger('trial_days')->default(0);
            }
        });

        Schema::table('billing_subscriptions', function (Blueprint $table) {
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('ended_at')->nullable();
            // SQLite rebuilds a table to add a foreign key, and the rebuild fires every ON DELETE rule of
            // the tables that point at it (nulling payments, cascading items). SQLite is a development and
            // test engine here, so it gets a plain indexed column; pgsql and mysql get the real constraint.
            if (DB::connection()->getDriverName() === 'sqlite') {
                $table->unsignedBigInteger('pending_plan_id')->nullable()->index();
            } else {
                $table->foreignId('pending_plan_id')->nullable()->constrained('billing_plans')->nullOnDelete();
            }
            $table->string('pending_billing_cycle')->nullable();
            $table->index(['team_id', 'status']);
            $table->index(['status', 'current_period_end']);
        });

        $this->retireDuplicateLiveSubscriptions();
        $this->createOneLivePerTeamIndex();
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS billing_subscriptions_one_live_per_team');

        Schema::table('billing_subscriptions', function (Blueprint $table) {
            if (DB::connection()->getDriverName() === 'sqlite') {
                $table->dropIndex(['pending_plan_id']);
            } else {
                $table->dropForeign(['pending_plan_id']);
            }
            $table->dropIndex(['team_id', 'status']);
            $table->dropIndex(['status', 'current_period_end']);
            $table->dropColumn(['cancel_at_period_end', 'ended_at', 'pending_plan_id', 'pending_billing_cycle']);
        });

        Schema::table('billing_plans', function (Blueprint $table) {
            $table->dropColumn('trial_days');
        });
    }

    private function retireDuplicateLiveSubscriptions(): void
    {
        $teamIds = DB::table('billing_subscriptions')
            ->whereIn('status', ['active', 'trialing', 'past_due'])
            ->groupBy('team_id')
            ->havingRaw('count(*) > 1')
            ->pluck('team_id');

        $now = now()->toDateTimeString();

        foreach ($teamIds as $teamId) {
            $live = DB::table('billing_subscriptions')
                ->where('team_id', $teamId)
                ->whereIn('status', ['active', 'trialing', 'past_due'])
                ->orderByDesc('id')
                ->get();

            $keep = $live->shift();

            foreach ($live as $duplicate) {
                DB::table('billing_subscriptions')->where('id', $duplicate->id)->update([
                    'status' => 'canceled',
                    'canceled_at' => $duplicate->canceled_at ?? $now,
                    'ended_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('billing_audit_events')->insert([
                    'team_id' => $teamId,
                    'actor_id' => null,
                    'action' => 'subscription.deduplicated',
                    'subject_type' => 'subscription',
                    'subject_id' => $duplicate->id,
                    'meta' => json_encode([
                        'kept_subscription_id' => $keep->id,
                        'previous_status' => $duplicate->status,
                        'reason' => 'A team may hold one live subscription; the newest was kept.',
                    ]),
                    'created_at' => $now,
                ]);
            }
        }
    }

    private function createOneLivePerTeamIndex(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['sqlite', 'pgsql'], true)) {
            DB::statement('CREATE UNIQUE INDEX billing_subscriptions_one_live_per_team ON billing_subscriptions (team_id) WHERE status IN '.self::LIVE);
        }
    }
};
