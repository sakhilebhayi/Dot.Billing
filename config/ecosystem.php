<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Dot Platform Registry
    |--------------------------------------------------------------------------
    | Every platform in the Dot Ecosystem, shared verbatim across all of them
    | (see InfoDot's original config/ecosystem.php, the source of this file).
    | Used to power cross-platform "discover" surfaces (e.g. error pages)
    | without hardcoding a fixed subset -- add a platform here once and it
    | appears everywhere automatically. Update URLs per environment via the
    | matching DOT_*_URL env vars.
    */

    'platforms' => [
        'infodot' => ['name' => 'InfoDot', 'url' => env('DOT_INFODOT_URL', 'https://infodot.app'), 'icon' => 'home', 'accent' => '#2f8fd6', 'active' => true],
        'agents' => ['name' => 'Dot.Agents', 'url' => env('DOT_AGENTS_URL', 'https://agents.infodot.app'), 'icon' => 'smart_toy', 'accent' => '#f1c62e', 'active' => true],
        'analytics' => ['name' => 'Dot.Analytics', 'url' => env('DOT_ANALYTICS_URL', 'https://analytics.infodot.app'), 'icon' => 'insights', 'accent' => '#f1c62e', 'active' => true],
        'auction' => ['name' => 'Dot.Auction', 'url' => env('DOT_AUCTION_URL', 'https://auction.infodot.app'), 'icon' => 'gavel', 'accent' => '#f1c62e', 'active' => true],
        'billing' => ['name' => 'Dot.Billing', 'url' => env('DOT_BILLING_URL', 'https://billing.infodot.app'), 'icon' => 'credit_card', 'accent' => '#f1c62e', 'active' => true],
        'central' => ['name' => 'Dot.Central', 'url' => env('DOT_CENTRAL_URL', 'https://central.infodot.app'), 'icon' => 'hub', 'accent' => '#e8bd3d', 'active' => true],
        'charts' => ['name' => 'Dot.Charts', 'url' => env('DOT_CHARTS_URL', 'https://charts.infodot.app'), 'icon' => 'candlestick_chart', 'accent' => '#f1c62e', 'active' => false],
        'design' => ['name' => 'Dot.Design', 'url' => env('DOT_DESIGN_URL', 'https://design.infodot.app'), 'icon' => 'palette', 'accent' => '#efb93a', 'active' => true],
        'dopemine' => ['name' => 'Dot.Dopemine', 'url' => env('DOT_DOPEMINE_URL', 'https://dopemine.infodot.app'), 'icon' => 'bolt', 'accent' => '#e8b923', 'active' => true],
        'ehail' => ['name' => 'Dot.Ehail', 'url' => env('DOT_EHAIL_URL', 'https://ehail.infodot.app'), 'icon' => 'local_taxi', 'accent' => '#e2b13d', 'active' => true],
        'emall' => ['name' => 'Dot.Emall', 'url' => env('DOT_EMALL_URL', 'https://emall.infodot.app'), 'icon' => 'storefront', 'accent' => '#eec13f', 'active' => true],
        'engage' => ['name' => 'Dot.Engage', 'url' => env('DOT_ENGAGE_URL', 'https://engage.infodot.app'), 'icon' => 'campaign', 'accent' => '#e8bb2c', 'active' => true],
        'farms' => ['name' => 'Dot.Farms', 'url' => env('DOT_FARMS_URL', 'https://farms.infodot.app'), 'icon' => 'agriculture', 'accent' => '#f0c862', 'active' => true],
        'files' => ['name' => 'Dot.Files', 'url' => env('DOT_FILES_URL', 'https://files.infodot.app'), 'icon' => 'folder', 'accent' => '#d9a018', 'active' => true],
        'finance' => ['name' => 'Dot.Finance', 'url' => env('DOT_FINANCE_URL', 'https://finance.infodot.app'), 'icon' => 'payments', 'accent' => '#e8af39', 'active' => true],
        'forms' => ['name' => 'Dot.Forms', 'url' => env('DOT_FORMS_URL', 'https://forms.infodot.app'), 'icon' => 'dynamic_form', 'accent' => '#f1c62e', 'active' => true],
        'hr' => ['name' => 'Dot.HR', 'url' => env('DOT_HR_URL', 'https://hr.infodot.app'), 'icon' => 'badge', 'accent' => '#f0b91c', 'active' => true],
        'memory' => ['name' => 'Dot.Memory', 'url' => env('DOT_MEMORY_URL', 'https://memory.infodot.app'), 'icon' => 'memory', 'accent' => '#f4c94c', 'active' => true],
        'mines' => ['name' => 'Dot.Mines', 'url' => env('DOT_MINES_URL', 'https://mines.infodot.app'), 'icon' => 'terrain', 'accent' => '#f1c62e', 'active' => true],
        'notify' => ['name' => 'Dot.Notify', 'url' => env('DOT_NOTIFY_URL', 'https://notify.infodot.app'), 'icon' => 'notifications', 'accent' => '#f0c33a', 'active' => true],
        'plug' => ['name' => 'Dot.Plug', 'url' => env('DOT_PLUG_URL', 'https://plug.infodot.app'), 'icon' => 'extension', 'accent' => '#f0c33a', 'active' => true],
        'press' => ['name' => 'Dot.Press', 'url' => env('DOT_PRESS_URL', 'https://press.infodot.app'), 'icon' => 'newspaper', 'accent' => '#f0c33a', 'active' => true],
        'projects' => ['name' => 'Dot.Projects', 'url' => env('DOT_PROJECTS_URL', 'https://projects.infodot.app'), 'icon' => 'folder_special', 'accent' => '#f0c33a', 'active' => true],
        'pulse' => ['name' => 'Dot.Pulse', 'url' => env('DOT_PULSE_URL', 'https://pulse.infodot.app'), 'icon' => 'favorite', 'accent' => '#f1c62e', 'active' => true],
        'sheet' => ['name' => 'Dot.Sheet', 'url' => env('DOT_SHEET_URL', 'https://sheet.infodot.app'), 'icon' => 'table_chart', 'accent' => '#e3ab1f', 'active' => true],
        'tasks' => ['name' => 'Dot.Tasks', 'url' => env('DOT_TASKS_URL', 'https://tasks.infodot.app'), 'icon' => 'task_alt', 'accent' => '#f2a803', 'active' => true],
        'tutor' => ['name' => 'Dot.Tutor', 'url' => env('DOT_TUTOR_URL', 'https://tutor.infodot.app'), 'icon' => 'school', 'accent' => '#f1c62e', 'active' => true],
        'docs' => ['name' => 'Dot.docs', 'url' => env('DOT_DOCS_URL', 'https://docs.infodot.app'), 'icon' => 'description', 'accent' => '#f1c62e', 'active' => true],
    ],

    /*
    |--------------------------------------------------------------------------
    | Handoff Token TTL
    |--------------------------------------------------------------------------
    | Short-lived tokens used for cross-platform SSO. Measured in minutes.
    | (Currently only InfoDot issues these, but the setting lives in the
    | shared registry so any platform that starts issuing tokens inherits
    | the same env-configurable default rather than hardcoding its own.)
    */

    'handoff_ttl' => env('ECOSYSTEM_HANDOFF_TTL', 5),

];
