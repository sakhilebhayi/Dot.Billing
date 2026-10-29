<?php

namespace App\Services\Billing;

use App\Models\InfodotCompany;
use App\Models\Team;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the registry of InfoDot companies (docs/company-registry.md).
 *
 * Creates no team and no user. When a team already exists for the company it follows InfoDot's name and carries
 * a deleted flag (teams.infodot_deleted_at). A deletion is only ever FLAGGED: no subscription, invoice or payment
 * is cancelled, ended or changed, because money may still be owed or held. Every change writes one audit entry;
 * an unchanged repeat writes none.
 */
class CompanyRegistry
{
    public const REGISTERED = 'registered';

    public const UPDATED = 'updated';

    public const UNCHANGED = 'unchanged';

    public const DELETED = 'deleted';

    public function __construct(private AuditLog $audit) {}

    /** @return 'registered'|'updated'|'unchanged'|'deleted' */
    public function apply(string $companyUuid, string $name, bool $deleted): string
    {
        $uuid = strtolower($companyUuid);

        try {
            return DB::transaction(fn () => $this->applyLocked($uuid, $name, $deleted));
        } catch (UniqueConstraintViolationException) {
            // Two first calls raced; the row exists now, so apply again as an update.
            return DB::transaction(fn () => $this->applyLocked($uuid, $name, $deleted));
        }
    }

    private function applyLocked(string $uuid, string $name, bool $deleted): string
    {
        $now = now();
        $company = InfodotCompany::where('company_uuid', $uuid)->lockForUpdate()->first();
        $team = Team::where('infodot_team_uuid', $uuid)->lockForUpdate()->first();

        $events = [];
        $previousName = null;

        if (! $company) {
            $company = InfodotCompany::create([
                'company_uuid' => $uuid,
                'name' => $name,
                'registered_at' => $now,
                'deleted_at' => $deleted ? $now : null,
            ]);
            $events[] = 'company.registered';
            $status = $deleted ? self::DELETED : self::REGISTERED;

            if ($deleted) {
                $events[] = 'company.deleted';
            }
        } else {
            $status = self::UNCHANGED;

            if ($company->name !== $name) {
                $previousName = $company->name;
                $company->name = $name;
                $events[] = 'company.renamed';
                $status = self::UPDATED;
            }

            if ($deleted && ! $company->isDeleted()) {
                $company->deleted_at = $now;
                $events[] = 'company.deleted';
                $status = self::DELETED;
            } elseif (! $deleted && $company->isDeleted()) {
                $company->deleted_at = null;
                $events[] = 'company.restored';
                $status = self::UPDATED;
            }

            if ($company->isDirty()) {
                $company->save();
            }
        }

        // The team (if one exists) follows the same facts, and is compared on its own: it may be out of step
        // with a registry row that is already up to date (for example a team created after the registry row).
        if ($team) {
            $teamChange = [];

            if ($team->name !== $name) {
                $teamChange['name'] = $name;
                $previousName ??= $team->name;
                if (! in_array('company.renamed', $events, true)) {
                    $events[] = 'company.renamed';
                    $status = $status === self::UNCHANGED ? self::UPDATED : $status;
                }
            }

            if ($deleted && $team->infodot_deleted_at === null) {
                $teamChange['infodot_deleted_at'] = $now;
                if (! in_array('company.deleted', $events, true)) {
                    $events[] = 'company.deleted';
                    $status = self::DELETED;
                }
            } elseif (! $deleted && $team->infodot_deleted_at !== null) {
                $teamChange['infodot_deleted_at'] = null;
                if (! in_array('company.restored', $events, true)) {
                    $events[] = 'company.restored';
                    $status = $status === self::UNCHANGED ? self::UPDATED : $status;
                }
            }

            if ($teamChange !== []) {
                $team->forceFill($teamChange)->save();
            }
        }

        foreach ($events as $action) {
            $this->audit->record($action, $team, null, null, array_filter([
                'company_uuid' => $uuid,
                'name' => $name,
                'previous_name' => $action === 'company.renamed' ? $previousName : null,
                'team_linked' => $team !== null,
            ], fn ($v) => $v !== null));
        }

        return $status;
    }
}
