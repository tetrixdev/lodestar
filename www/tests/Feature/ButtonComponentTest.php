<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class ButtonComponentTest extends TestCase
{
    public function test_primary_button_renders_with_gray_idiom_and_no_uppercase(): void
    {
        $html = Blade::render('<x-button>Save</x-button>');

        $this->assertStringContainsString('<button', $html);
        $this->assertStringContainsString('type="submit"', $html);
        $this->assertStringContainsString('bg-gray-800', $html);
        $this->assertStringContainsString('px-4 py-2', $html);
        $this->assertStringContainsString('Save', $html);
        // Breeze uppercase styling must be dropped.
        $this->assertStringNotContainsString('uppercase', $html);
        $this->assertStringNotContainsString('tracking-widest', $html);
    }

    public function test_accent_sm_variant_renders_emerald_and_compact_sizing(): void
    {
        $html = Blade::render('<x-button variant="accent" size="sm">Approve plan</x-button>');

        $this->assertStringContainsString('bg-emerald-600', $html);
        $this->assertStringContainsString('hover:bg-emerald-700', $html);
        $this->assertStringContainsString('px-3 py-1.5', $html);
        $this->assertStringContainsString('text-xs', $html);
        $this->assertStringContainsString('font-medium', $html);
        $this->assertStringContainsString('Approve plan', $html);
    }

    public function test_danger_and_secondary_variants(): void
    {
        $danger = Blade::render('<x-button variant="danger">Delete</x-button>');
        $this->assertStringContainsString('bg-red-600', $danger);

        $secondary = Blade::render('<x-button variant="secondary">Cancel</x-button>');
        $this->assertStringContainsString('bg-white', $secondary);
        $this->assertStringContainsString('border-gray-300', $secondary);
        $this->assertStringContainsString('text-gray-700', $secondary);
    }

    public function test_as_anchor_renders_link(): void
    {
        $html = Blade::render('<x-button as="a" href="/foo">Link</x-button>');

        $this->assertStringContainsString('<a', $html);
        $this->assertStringContainsString('href="/foo"', $html);
        $this->assertStringNotContainsString('<button', $html);
    }

    public function test_caller_attributes_and_classes_merge(): void
    {
        $html = Blade::render('<x-button class="ml-2" x-data="{}" @click="go">Go</x-button>');

        $this->assertStringContainsString('ml-2', $html);
        $this->assertStringContainsString('inline-flex', $html);
        $this->assertStringContainsString('x-data="{}"', $html);
    }

    public function test_wrapped_primary_button_still_renders(): void
    {
        $html = Blade::render('<x-primary-button>Legacy</x-primary-button>');

        $this->assertStringContainsString('<button', $html);
        $this->assertStringContainsString('bg-gray-800', $html);
        $this->assertStringContainsString('Legacy', $html);
        $this->assertStringNotContainsString('uppercase', $html);
    }

    public function test_wrapped_secondary_button_keeps_button_type(): void
    {
        $html = Blade::render('<x-secondary-button>Cancel</x-secondary-button>');

        $this->assertStringContainsString('type="button"', $html);
        $this->assertStringContainsString('bg-white', $html);
    }

    public function test_wrapped_danger_button_still_renders(): void
    {
        $html = Blade::render('<x-danger-button>Remove</x-danger-button>');

        $this->assertStringContainsString('bg-red-600', $html);
        $this->assertStringContainsString('Remove', $html);
    }
}
