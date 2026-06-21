<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Playbook;
use App\Models\User;
use Database\Seeders\SystemPlaybookSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The framework "stack pack": a project tagged `stack = laravel` gets the laravel
 * structure pack composed into its build/review phases — and nowhere else.
 */
class PlaybookStackPackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemPlaybookSeeder::class);
    }

    private function project(User $user, string $stack): \App\Models\Project
    {
        return $user->projects()->create(['name' => 'P', 'slug' => 'p-'.uniqid(), 'stack' => $stack]);
    }

    public function test_a_laravel_project_gets_the_pack_in_steered_phases(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user, 'laravel');

        foreach (['plan', 'develop', 'ai_review'] as $phase) {
            $composed = Playbook::compose($user, $project, $phase);
            $this->assertStringContainsString('LARAVEL STRUCTURE', $composed['body'], "pack missing from {$phase}");
            $this->assertContains('Laravel structure pack', array_column($composed['layers'], 'title'));
        }
    }

    public function test_a_non_laravel_project_does_not_get_the_pack(): void
    {
        $user = User::factory()->create();

        // A real stack that has no pack (yet) — still NOT NULL, just not steered.
        $composed = Playbook::compose($user, $this->project($user, 'python'), 'develop');

        $this->assertStringNotContainsString('LARAVEL STRUCTURE', $composed['body']);
    }

    public function test_the_pack_only_steers_build_and_review_phases(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user, 'laravel');

        $this->assertStringNotContainsString('LARAVEL STRUCTURE', Playbook::compose($user, $project, 'merge')['body']);
        $this->assertStringNotContainsString('LARAVEL STRUCTURE', Playbook::compose($user, $project, 'main')['body']);
    }

    public function test_the_pack_is_not_advertised_in_the_main_catalog(): void
    {
        $user = User::factory()->create();
        $main = Playbook::compose($user, $this->project($user, 'laravel'), 'main')['body'];

        $this->assertStringContainsString('`work`', $main);          // a real on-demand playbook is advertised
        $this->assertStringNotContainsString('`laravel`', $main);    // the stack pack is not
    }

    public function test_main_carries_the_generic_structure_doctrine(): void
    {
        $user = User::factory()->create();

        $this->assertStringContainsString(
            'STRUCTURE & COHESION',
            Playbook::compose($user, $this->project($user, 'python'), 'main')['body']
        );
    }
}
