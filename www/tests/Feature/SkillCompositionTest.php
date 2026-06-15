<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Skill;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The composition engine: a phase prompt is built system → team → personal →
 * project, append by default, an overwrite layer wiping everything above it,
 * and the personal layer dropped when the team forbids it. Named skills don't
 * compose — they resolve to the most-specific scope.
 */
class SkillCompositionTest extends TestCase
{
    use RefreshDatabase;

    /** Create a slot at a scope with one active version. */
    private function slot(string $scope, ?object $owner, string $key, string $mode, string $body): Skill
    {
        $slot = Skill::create([
            'scope' => $scope,
            'owner_type' => $owner ? $owner->getMorphClass() : null,
            'owner_id' => $owner?->getKey(),
            'key' => $key,
            'title' => "{$scope} {$key}",
        ]);
        $slot->publish("{$scope} {$key}", null, $body, mode: $mode);

        return $slot;
    }

    public function test_append_layers_concatenate_in_scope_order(): void
    {
        $user = User::factory()->create();
        $team = Team::create(['name' => 'T', 'owner_user_id' => $user->id]);
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p', 'team_id' => $team->id]);

        $this->slot(Skill::SCOPE_SYSTEM, null, 'develop', Skill::MODE_APPEND, 'SYS');
        $this->slot(Skill::SCOPE_TEAM, $team, 'develop', Skill::MODE_APPEND, 'TEAM');
        $this->slot(Skill::SCOPE_PROJECT, $project, 'develop', Skill::MODE_APPEND, 'PROJ');
        $this->slot(Skill::SCOPE_PERSONAL, $user, 'develop', Skill::MODE_APPEND, 'ME');

        $composed = Skill::compose($user, $project, 'develop');

        // Personal is last so the person has the final say.
        $this->assertSame("SYS\n\nTEAM\n\nPROJ\n\nME", $composed['body']);
        $this->assertSame(['system', 'team', 'project', 'personal'], array_column($composed['layers'], 'scope'));
    }

    public function test_an_overwrite_layer_discards_everything_above_it(): void
    {
        $user = User::factory()->create();
        $team = Team::create(['name' => 'T', 'owner_user_id' => $user->id]);
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p', 'team_id' => $team->id]);

        $this->slot(Skill::SCOPE_SYSTEM, null, 'develop', Skill::MODE_APPEND, 'SYS');
        $this->slot(Skill::SCOPE_TEAM, $team, 'develop', Skill::MODE_APPEND, 'TEAM');
        // Project overwrites: system + team discarded, project becomes the base.
        $this->slot(Skill::SCOPE_PROJECT, $project, 'develop', Skill::MODE_OVERWRITE, 'PROJ-ONLY');
        // Personal appends onto the project base (personal has the final say).
        $this->slot(Skill::SCOPE_PERSONAL, $user, 'develop', Skill::MODE_APPEND, 'ME');

        $composed = Skill::compose($user, $project, 'develop');

        $this->assertSame("PROJ-ONLY\n\nME", $composed['body']);
        $this->assertSame(['project', 'personal'], array_column($composed['layers'], 'scope'));
    }

    public function test_a_personal_overwrite_wins_outright(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);

        $this->slot(Skill::SCOPE_SYSTEM, null, 'develop', Skill::MODE_APPEND, 'SYS');
        $this->slot(Skill::SCOPE_PROJECT, $project, 'develop', Skill::MODE_APPEND, 'PROJ');
        // Personal is last and overwrites → nothing above it survives.
        $this->slot(Skill::SCOPE_PERSONAL, $user, 'develop', Skill::MODE_OVERWRITE, 'ONLY-ME');

        $composed = Skill::compose($user, $project, 'develop');

        $this->assertSame('ONLY-ME', $composed['body']);
        $this->assertSame(['personal'], array_column($composed['layers'], 'scope'));
    }

    public function test_personal_layer_is_dropped_when_the_team_forbids_it(): void
    {
        $user = User::factory()->create();
        $team = Team::create(['name' => 'T', 'owner_user_id' => $user->id, 'allow_personal_instructions' => false]);
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p', 'team_id' => $team->id]);

        $this->slot(Skill::SCOPE_SYSTEM, null, 'develop', Skill::MODE_APPEND, 'SYS');
        $this->slot(Skill::SCOPE_PERSONAL, $user, 'develop', Skill::MODE_APPEND, 'ME');

        $composed = Skill::compose($user, $project, 'develop');

        $this->assertSame('SYS', $composed['body']);
        $this->assertSame(['system'], array_column($composed['layers'], 'scope'));
    }

    public function test_only_the_active_version_composes(): void
    {
        $user = User::factory()->create();
        $slot = $this->slot(Skill::SCOPE_SYSTEM, null, 'plan', Skill::MODE_APPEND, 'V1');
        // A newer ACTIVE version supersedes the old one (now archived).
        $slot->publish('plan', null, 'V2');
        // A proposed version must NOT leak into the composed body.
        $slot->propose('plan', null, 'PROPOSED', $user, byAi: false);

        $composed = Skill::compose($user, null, 'plan');

        $this->assertSame('V2', $composed['body']);
    }

    public function test_main_advertises_named_skills_in_its_catalog(): void
    {
        $user = User::factory()->create();
        $this->slot(Skill::SCOPE_SYSTEM, null, 'main', Skill::MODE_APPEND, 'You are an agent.');

        // A named skill with a summary; and a phase skill that must NOT be catalogued.
        $named = $this->slot(Skill::SCOPE_PERSONAL, $user, 'db-recipe', Skill::MODE_APPEND, 'recipe body');
        $named->activeVersion()->update(['summary' => 'Migrate the database safely.']);
        $this->slot(Skill::SCOPE_SYSTEM, null, 'develop', Skill::MODE_APPEND, 'develop body');

        $body = Skill::compose($user, null, 'main')['body'];

        $this->assertStringContainsString('Available skills', $body);
        $this->assertStringContainsString('db-recipe', $body);
        $this->assertStringContainsString('Migrate the database safely.', $body);
        $this->assertStringNotContainsString('develop', $body); // phase keys aren't catalogued
    }

    public function test_named_skill_resolves_to_the_most_specific_scope(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);

        $this->slot(Skill::SCOPE_SYSTEM, null, 'db-recipe', Skill::MODE_APPEND, 'SYS-RECIPE');
        $this->slot(Skill::SCOPE_PROJECT, $project, 'db-recipe', Skill::MODE_APPEND, 'PROJ-RECIPE');

        // Named keys don't compose: the most-specific present scope wins outright.
        $this->assertSame('PROJ-RECIPE', Skill::resolveNamed($user, $project, 'db-recipe')->body);

        // A personal slot wins over the project (personal is most-specific).
        $this->slot(Skill::SCOPE_PERSONAL, $user, 'db-recipe', Skill::MODE_APPEND, 'MY-RECIPE');
        $this->assertSame('MY-RECIPE', Skill::resolveNamed($user, $project, 'db-recipe')->body);

        // Without the project/personal, it falls back to system.
        $this->assertSame('SYS-RECIPE', Skill::resolveNamed(User::factory()->create(), null, 'db-recipe')->body);
    }
}
