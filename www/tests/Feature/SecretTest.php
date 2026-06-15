<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SecretTest extends TestCase
{
    use RefreshDatabase;

    public function test_value_is_encrypted_at_rest(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $secret = $user->personalSecrets()->create(['project_id' => null, 'key' => 'TOK', 'value' => 'super-secret']);

        // Decrypts through the model…
        $this->assertSame('super-secret', $secret->fresh()->value);
        // …but the raw column is not the plaintext.
        $raw = \DB::table('personal_secrets')->where('id', $secret->id)->value('value');
        $this->assertNotSame('super-secret', $raw);
    }

    public function test_only_an_approver_can_manage_the_manifest(): void
    {
        $owner = User::factory()->create();
        $team = Team::create(['name' => 'T', 'owner_user_id' => $owner->id]);
        $project = $owner->projects()->create(['name' => 'P', 'slug' => 'p', 'team_id' => $team->id]);
        $member = User::factory()->create();
        $team->members()->attach($member->id, ['role' => 'member', 'can_approve_prompts' => false]);

        $this->actingAs($member)->post(route('secrets.requirements.store', $project), ['key' => 'X'])
            ->assertForbidden();
        $this->actingAs($owner)->post(route('secrets.requirements.store', $project), ['key' => 'X', 'description' => 'a key'])
            ->assertRedirect();

        $this->assertDatabaseHas('project_secret_requirements', ['project_id' => $project->id, 'key' => 'X']);
    }

    public function test_a_member_sets_their_own_value_and_project_scope_wins(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $project->secretRequirements()->create(['key' => 'API']);

        // A global value, then a project-scoped override.
        $this->actingAs($user)->post(route('secrets.values.store', $project), ['key' => 'API', 'value' => 'global'])->assertRedirect();
        $this->actingAs($user)->post(route('secrets.values.store', $project), ['key' => 'API', 'value' => 'scoped', 'project_scoped' => 1])->assertRedirect();

        Sanctum::actingAs($user, ['agent']);
        $this->get(route('api.projects.secrets', $project))
            ->assertOk()
            ->assertSee('API=scoped'); // project-scoped value beats the global one
    }

    public function test_bundle_reports_missing_keys_with_409(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $project->secretRequirements()->create(['key' => 'NEEDED']);

        Sanctum::actingAs($user, ['agent']);
        $this->get(route('api.projects.secrets', $project))
            ->assertStatus(409)
            ->assertSee('missing: NEEDED');
    }

    public function test_bundle_is_scoped_to_the_caller(): void
    {
        $stranger = User::factory()->create();
        $project = User::factory()->create()->projects()->create(['name' => 'X', 'slug' => 'x']);

        Sanctum::actingAs($stranger, ['agent']);
        $this->get(route('api.projects.secrets', $project))->assertForbidden();
    }
}
