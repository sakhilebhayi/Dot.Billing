<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Public sign-up is closed in production: accounts are created by InfoDot at first sign-in. The suite itself
 * runs with ALLOW_REGISTRATION=true (existing tests exercise registration), so the production default is proven
 * by reading the config in a child process that does not have the variable.
 */
class RegistrationClosedTest extends TestCase
{
    private function featuresWith(?string $allow): array
    {
        $env = ['ALLOW_REGISTRATION' => $allow ?? false, 'APP_ENV' => 'production'];
        $code = 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo json_encode(array_values(config("fortify.features")));';
        $process = new Process([PHP_BINARY, '-r', $code], dirname(__DIR__, 2), $env);
        $process->mustRun();

        return json_decode($process->getOutput(), true);
    }

    public function test_registration_is_off_when_the_variable_is_not_set(): void
    {
        $this->assertNotContains('registration', $this->featuresWith(null));
    }

    public function test_registration_is_off_when_the_variable_is_false(): void
    {
        $this->assertNotContains('registration', $this->featuresWith('false'));
    }

    public function test_registration_is_only_on_when_explicitly_enabled(): void
    {
        $this->assertContains('registration', $this->featuresWith('true'));
    }

    public function test_the_env_example_ships_with_registration_closed(): void
    {
        $this->assertStringContainsString('ALLOW_REGISTRATION=false', file_get_contents(dirname(__DIR__, 2).'/.env.example'));
    }
}
