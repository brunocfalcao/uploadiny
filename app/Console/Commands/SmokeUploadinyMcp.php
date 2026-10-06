<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Project;
use App\UploadChunk;
use App\UploadImage;
use App\UploadinyTokenAbility;
use App\User;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use RuntimeException;
use Throwable;

final class SmokeUploadinyMcp extends Command
{
    protected $signature = 'uploadiny:mcp-smoke {--ca= : Trusted CA certificate bundle for local HTTPS}';

    protected $description = 'Smoke-test the configured HTTPS MCP endpoint with disposable private fixtures and credentials';

    /** @var list<int> */
    private array $tokenIds = [];

    private string $step = 'fixture setup';

    public function handle(): int
    {
        $project = null;
        $chunk = null;
        $images = [];
        $disk = Storage::disk('local');
        $fixture = 'mcp-smoke/'.Str::uuid();
        try {
            $user = User::query()->sole();
            $project = Project::create(['name' => 'MCP smoke '.Str::uuid(), 'slug' => 'mcp-smoke-'.Str::uuid()]);
            $chunk = UploadChunk::create(['upload_project_id' => $project->id, 'status' => 'complete', 'expected_images' => 2, 'completed_at' => now()]);
            $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=', true);
            $disk->put($fixture.'/original.png', $png);
            $disk->put($fixture.'/annotated.png', $png);
            $videoPath = $fixture.'/recording.mp4';
            $process = Process::timeout(10)->run([
                config('services.uploadiny.ffmpeg_binary'), '-nostdin', '-v', 'error', '-f', 'lavfi',
                '-i', 'color=c=blue:s=64x64:d=2', '-an', '-c:v', 'mpeg4', '-pix_fmt', 'yuv420p', '-y', $disk->path($videoPath),
            ]);
            $this->require($process->successful(), 'The recording fixture needs a working FFmpeg installation.');
            foreach ([['original.png', 'image/png', 'Make the buttons bigger.'], ['recording.mp4', 'video/mp4', 'At half a second, inspect the menu.']] as [$file, $mime, $comment]) {
                $images[] = UploadImage::create([
                    'uuid' => (string) Str::uuid(), 'project_id' => $project->id, 'chunk_id' => $chunk->id,
                    'name' => 'mcp-smoke-'.$file, 'original_name' => $file, 'path' => $fixture.'/'.$file,
                    'mime_type' => $mime, 'size' => $disk->size($fixture.'/'.$file), 'comments' => $comment,
                    'feedback_revision' => 2, 'description_status' => 'complete',
                    'annotated_path' => $mime === 'image/png' ? $fixture.'/annotated.png' : null,
                ]);
            }
            $agent = $this->token($user, UploadinyTokenAbility::agent());
            $phone = $this->token($user, UploadinyTokenAbility::phone());

            $this->step = 'authentication';
            $this->require($this->rpc(null, 'tools/list')->status() === 401, 'Unauthenticated requests must be rejected.');
            $this->require($this->rpc('invalid-smoke-key', 'tools/list')->status() === 401, 'Invalid keys must be rejected.');
            $this->require($this->rpc($phone, 'tools/list')->status() === 403, 'Phone keys must not read feedback.');

            $this->step = 'initialization';
            $initialized = $this->rpc($agent, 'initialize', ['protocolVersion' => '2025-11-25', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'Uploadiny smoke', 'version' => '1.0']]);
            $this->require($initialized->successful() && $initialized->json('result.serverInfo.name') === 'Uploadiny', 'The MCP initialization handshake failed.');
            $this->require($this->rpc($agent, 'notifications/initialized', [], false)->status() === 202, 'The initialized notification failed.');

            $this->step = 'tool discovery';
            $tools = $this->rpc($agent, 'tools/list');
            $this->require(array_column($tools->json('result.tools') ?? [], 'name') === ['list_projects', 'get_feedback', 'get_asset', 'get_recording_frames'], 'Unexpected MCP tools.');

            $this->step = 'project and feedback retrieval';
            $projects = $this->callTool($agent, 'list_projects');
            $this->require(in_array($project->canonical, array_column($projects['structuredContent']['projects'], 'canonical'), true), 'The fixture project code was not listed.');
            $feedback = $this->callTool($agent, 'get_feedback', ['project_canonical' => $project->canonical]);
            $assets = $feedback['structuredContent']['chunk']['images'];
            $this->require(count($assets) === 2 && $assets[0]['id'] === $images[0]->uuid && $assets[0]['comments'] === 'Make the buttons bigger.' && $assets[0]['revision'] === 2, 'Exact feedback was not delivered.');

            $this->step = 'private image delivery';
            foreach (['original', 'annotated'] as $variant) {
                $asset = $this->callTool($agent, 'get_asset', ['asset_id' => $images[0]->uuid, 'variant' => $variant]);
                $this->require($asset['content'][1]['type'] === 'image' && base64_decode($asset['content'][1]['data'], true) === $png, 'Image bytes did not match the fixture.');
            }

            $this->step = 'recording frame delivery';
            $frames = $this->callTool($agent, 'get_recording_frames', ['asset_id' => $images[1]->uuid, 'timestamps' => [0, 0.5]]);
            foreach ([2, 4] as $index) {
                $size = getimagesizefromstring(base64_decode($frames['content'][$index]['data'], true));
                $this->require($size !== false && $size[0] === 64 && $size[1] === 64 && $size['mime'] === 'image/jpeg', 'Recording frames were not valid JPEGs.');
            }
            $this->require($frames['structuredContent']['frames'][1]['timestamp_seconds'] === 0.5, 'Frame timestamps were lost.');

            $this->step = 'credential revocation';
            PersonalAccessToken::findToken($agent)?->delete();
            $this->require($this->rpc($agent, 'tools/list')->status() === 401, 'A revoked key still worked.');
            $this->info('MCP HTTPS smoke passed: authentication, initialization, four tools, exact feedback, both image variants, recording frames, and revocation.');

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error('MCP smoke failed at '.$this->step.'. Check the configured application URL, TLS trust, and FFmpeg installation.');
            $this->error(get_class($error) === RuntimeException::class ? $error->getMessage() : 'Failure type: '.get_class($error));

            return self::FAILURE;
        } finally {
            foreach ($images as $image) {
                $image->delete();
            }
            $chunk?->delete();
            $project?->delete();
            PersonalAccessToken::query()->whereIn('id', $this->tokenIds)->delete();
            $disk->deleteDirectory($fixture);
        }
    }

    /** @param list<string> $abilities */
    private function token(User $user, array $abilities): string
    {
        $token = $user->createToken('mcp-smoke-'.Str::uuid(), $abilities, now()->addMinutes(5));
        $this->tokenIds[] = $token->accessToken->getKey();

        return $token->plainTextToken;
    }

    /** @param array<string, mixed> $params */
    private function rpc(?string $key, string $method, array $params = [], bool $withId = true): Response
    {
        $body = ['jsonrpc' => '2.0', 'method' => $method, 'params' => (object) $params];
        if ($withId) {
            $body['id'] = 1;
        }
        $request = Http::acceptJson()->timeout(30);
        if ($this->option('ca')) {
            $request = $request->withOptions(['verify' => $this->option('ca')]);
        }
        if ($key !== null) {
            $request = $request->withToken($key);
        }

        return $request->post(rtrim(config('app.url'), '/').'/mcp', $body);
    }

    /** @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function callTool(string $key, string $name, array $arguments = []): array
    {
        $response = $this->rpc($key, 'tools/call', ['name' => $name, 'arguments' => (object) $arguments]);
        $result = $response->json('result');
        $this->require($response->successful() && is_array($result) && ($result['isError'] ?? true) === false, 'An MCP tool call failed.');

        return $result;
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    }
}
