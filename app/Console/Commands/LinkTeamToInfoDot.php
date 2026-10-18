<?php

namespace App\Console\Commands;

use App\Models\Team;
use Illuminate\Console\Command;

class LinkTeamToInfoDot extends Command
{
    protected $signature = 'infodot:link-team {team : This app\'s team id} {uuid : The InfoDot team uuid} {--promote : Turn a personal team into a company while linking it}';

    protected $description = 'Link an existing team (and the data in it) to a company in InfoDot';

    public function handle(): int
    {
        $team = Team::find($this->argument('team'));
        $uuid = (string) $this->argument('uuid');

        if (! $team) {
            $this->error('No such team.');

            return self::FAILURE;
        }

        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
            $this->error('That is not a uuid.');

            return self::FAILURE;
        }

        if ($team->personal_team && ! $this->option('promote')) {
            $this->error('This is a personal team, not a company. Re-run with --promote to turn it into a company and link it.');

            return self::FAILURE;
        }

        if ($team->infodot_team_uuid && $team->infodot_team_uuid !== $uuid) {
            $this->error('This team is already linked to a different company.');

            return self::FAILURE;
        }

        if (Team::where('infodot_team_uuid', $uuid)->where('id', '!=', $team->id)->exists()) {
            $this->error('Another team here is already linked to that company.');

            return self::FAILURE;
        }

        $promoted = $team->personal_team;
        $team->forceFill(['infodot_team_uuid' => $uuid, 'personal_team' => false])->save();

        if ($promoted) {
            $this->warn('The personal team was promoted to a company. Anyone InfoDot lists as a member of that company will now be attached to it when they sign in.');
        }

        $this->info("Team #{$team->id} \"{$team->name}\" is now linked to the InfoDot company {$uuid}.");

        return self::SUCCESS;
    }
}
