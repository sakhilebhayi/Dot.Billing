<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Livewire 3 bundles and starts Alpine. A second copy loaded from a CDN replaces window.Alpine and
 * breaks every Livewire re-render, so the layout must not load one.
 */
class SingleAlpineTest extends TestCase
{
    public function test_the_app_layout_does_not_load_a_second_alpine(): void
    {
        $layout = file_get_contents(dirname(__DIR__, 2).'/resources/views/layouts/app.blade.php');

        $this->assertIsString($layout);
        $this->assertDoesNotMatchRegularExpression('/<script[^>]+alpinejs/i', $layout);
        $this->assertStringContainsString('@livewireScripts', $layout);
    }
}
