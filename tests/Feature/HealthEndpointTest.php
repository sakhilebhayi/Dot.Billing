<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    public function test_up_answers_200_when_the_database_is_reachable(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_up_does_not_answer_200_when_the_database_is_unreachable(): void
    {
        config([
            'app.debug' => false,
            'database.default' => 'unreachable',
            'database.connections.unreachable' => ['driver' => 'sqlite', 'database' => '/nonexistent-folder/health.sqlite', 'prefix' => ''],
        ]);
        DB::purge('unreachable');

        $response = $this->get('/up');

        $response->assertStatus(500);
        $this->assertStringNotContainsString('nonexistent-folder', $response->getContent());
    }
}
