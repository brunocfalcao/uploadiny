<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Project;
use App\UploadImage;
use App\UploadinyTokenAbility;
use App\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
        $this->get(route('projects.show', $project))->assertSee($canonical)->assertSee('data-copy-text="'.$canonical.'"', false)->assertSee('Copy project code');

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

    public function test_an_interrupted_canonical_backfill_can_resume_without_reassigning_existing_codes(): void
    {
        $project = Project::factory()->create(['name' => 'Canonical interrupted', 'slug' => 'canonical-interrupted']);
        $image = UploadImage::factory()->create(['project_id' => $project->id, 'comments' => 'Preserve this feedback.']);
        $uuid = $project->uuid;
        $migration = require database_path('migrations/2026_10_06_144233_add_canonical_to_projects_table.php');
        $migration->down();
        $assignedId = DB::table('projects')->insertGetId(['name' => 'Canonical assigned', 'slug' => 'canonical-assigned', 'uuid' => (string) Str::uuid()]);
        $missingId = DB::table('projects')->insertGetId(['name' => 'Canonical missing', 'slug' => 'canonical-missing', 'uuid' => (string) Str::uuid()]);
        Schema::table('projects', function (Blueprint $table): void {
            $table->string('canonical', 6)->nullable()->unique();
        });
        DB::table('projects')->where('id', $assignedId)->update(['canonical' => 'abcxyz']);
        DB::table('migrations')->where('migration', '2026_10_06_144233_add_canonical_to_projects_table')->delete();
        $this->assertNull(DB::table('projects')->where('id', $missingId)->value('canonical'));

        $this->artisan('uploadiny:recover-canonicals')->assertSuccessful();
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();

        $assigned = Project::query()->findOrFail($assignedId);
        $missing = Project::query()->findOrFail($missingId);
        $this->assertSame('abcxyz', $assigned->canonical);
        $this->assertMatchesRegularExpression('/^[a-z]{6}$/', $missing->canonical);
        $this->assertNotSame($assigned->canonical, $missing->canonical);
        $this->assertSame($uuid, $project->fresh()?->uuid);
        $this->assertSame('Preserve this feedback.', $image->fresh()?->comments);
    }

    public function test_canonical_recovery_leaves_a_completed_installation_unchanged(): void
    {
        $project = Project::factory()->create(['slug' => 'canonical-recovery-complete']);
        $before = $project->fresh()->getAttributes();
        $migrations = DB::table('migrations')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

        $this->artisan('uploadiny:recover-canonicals')->assertSuccessful();

        $this->assertSame($before, $project->fresh()?->getAttributes());
        $this->assertSame($migrations, DB::table('migrations')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
    }

    public function test_canonical_recovery_rolls_back_a_failed_backfill_before_retry(): void
    {
        $project = Project::factory()->create(['slug' => 'canonical-recovery-failure']);
        $migration = '2026_10_06_144233_add_canonical_to_projects_table';
        DB::table('migrations')->where('migration', $migration)->delete();
        DB::table('projects')->where('id', $project->id)->update(['canonical' => null]);
        Schema::table('projects', fn (Blueprint $table) => $table->dropUnique(['canonical']));
        DB::unprepared("CREATE TRIGGER canonical_recovery_failure BEFORE UPDATE OF canonical ON projects BEGIN SELECT RAISE(ABORT, 'controlled recovery failure'); END");
        $this->assertFalse(Schema::hasIndex('projects', ['canonical'], 'unique'));

        $this->artisan('uploadiny:recover-canonicals')->assertFailed();

        $this->assertNull($project->fresh()?->canonical);
        $this->assertDatabaseMissing('migrations', ['migration' => $migration]);
        $this->assertFalse(Schema::hasIndex('projects', ['canonical'], 'unique'));
        DB::unprepared('DROP TRIGGER canonical_recovery_failure');
        $this->artisan('uploadiny:recover-canonicals')->assertSuccessful();
        $this->assertMatchesRegularExpression('/^[a-z]{6}$/', $project->fresh()?->canonical);
        $this->assertTrue(Schema::hasIndex('projects', ['canonical'], 'unique'));
        $this->assertDatabaseHas('migrations', ['migration' => $migration]);
    }
}
