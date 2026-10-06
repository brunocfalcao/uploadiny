<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Project;
use App\UploadImage;
use App\UploadinyTokenAbility;
use App\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProjectCanonicalTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_creation_assigns_a_code_that_survives_name_and_url_changes(): void
    {
        $user = User::factory()->create(['email' => 'canonical-owner@example.test']);
        $this->actingAs($user)->post(route('projects.store'), ['name' => 'Canonical project', 'slug' => 'canonical-project', 'canonical' => 'chosen'])->assertRedirect();
        $project = Project::query()->where('slug', 'canonical-project')->sole();
        $canonical = $project->canonical;
        $this->assertMatchesRegularExpression('/^[a-z]{6}$/', $canonical);
        $this->get(route('projects.show', $project))->assertSee($canonical)->assertSee('Copy code');

        $this->patch(route('projects.update', $project), ['name' => 'Renamed canonical project', 'slug' => 'renamed-canonical-project', 'canonical' => 'change'])->assertRedirect();

        $this->assertSame($canonical, $project->fresh()?->canonical);
        $this->assertSame('renamed-canonical-project', $project->fresh()?->slug);
        $this->get(route('projects.index'))->assertSee($canonical)->assertSee(route('agent-access.show'));
    }

    public function test_read_only_agents_retrieve_the_correct_project_using_its_code(): void
    {
        $user = User::factory()->create(['email' => 'canonical-reader@example.test']);
        $project = Project::factory()->create(['name' => 'Canonical primary', 'slug' => 'canonical-primary']);
        $project->forceFill(['canonical' => 'qmvkzr'])->save();
        $other = Project::factory()->create(['name' => 'Canonical other', 'slug' => 'canonical-other']);
        $image = UploadImage::factory()->create(['project_id' => $project->id, 'comments' => 'Make the primary buttons bigger.']);
        UploadImage::factory()->create(['project_id' => $other->id, 'comments' => 'Other project feedback.']);
        $url = route('api.feedback.latest', ['project' => $project->canonical]);
        $this->getJson($url)->assertUnauthorized();
        $this->withToken($user->createToken('canonical-phone', UploadinyTokenAbility::phone())->plainTextToken)->getJson($url)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withToken($user->createToken('canonical-agent', UploadinyTokenAbility::agent())->plainTextToken);

        $this->getJson($url)->assertOk()->assertJsonPath('project.canonical', 'qmvkzr')->assertJsonPath('chunk.images.0.id', $image->uuid)->assertJsonPath('chunk.images.0.comments', 'Make the primary buttons bigger.')->assertJsonCount(1, 'chunk.images');
        $this->getJson(route('api.projects.latest', $project))->assertOk()->assertJsonPath('project.canonical', 'qmvkzr');
        $this->getJson(route('api.projects.index'))->assertOk()->assertJsonPath('projects.1.canonical', 'qmvkzr');
        $this->getJson(route('api.feedback.latest', ['project' => '123456']))->assertNotFound();
        $this->getJson(route('api.feedback.latest', ['project' => 'zzzzzz']))->assertNotFound();
        $this->assertSame('Other project feedback.', $other->images()->sole()->comments);
    }

    public function test_database_rejects_duplicate_project_codes(): void
    {
        $first = Project::factory()->create(['name' => 'Canonical unique first', 'slug' => 'canonical-unique-first']);
        $second = Project::factory()->create(['name' => 'Canonical unique second', 'slug' => 'canonical-unique-second']);
        $this->assertNotSame($first->canonical, $second->canonical);
        $this->expectException(QueryException::class);

        $second->forceFill(['canonical' => $first->canonical])->save();
    }

    public function test_backfill_and_rollback_preserve_existing_projects_and_feedback(): void
    {
        $project = Project::factory()->create(['name' => 'Canonical legacy', 'slug' => 'canonical-legacy']);
        $image = UploadImage::factory()->create(['project_id' => $project->id, 'comments' => 'Keep this existing remark.']);
        $migration = require database_path('migrations/2026_10_06_144233_add_canonical_to_projects_table.php');
        $migration->down();
        $legacyId = DB::table('projects')->insertGetId(['name' => 'Canonical second legacy', 'slug' => 'canonical-second-legacy', 'uuid' => (string) Str::uuid()]);

        $migration->up();

        $restored = Project::query()->findOrFail($project->id);
        $second = Project::query()->findOrFail($legacyId);
        $this->assertMatchesRegularExpression('/^[a-z]{6}$/', $restored->canonical);
        $this->assertMatchesRegularExpression('/^[a-z]{6}$/', $second->canonical);
        $this->assertNotSame($restored->canonical, $second->canonical);
        $this->assertSame('canonical-legacy', $restored->slug);
        $this->assertSame($project->uuid, $restored->uuid);
        $this->assertSame('Keep this existing remark.', $image->fresh()?->comments);
        $this->assertSame($project->id, $image->fresh()?->project_id);
    }
}
