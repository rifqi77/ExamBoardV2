<?php

namespace Tests\Feature;

use App\Http\Controllers\AiGenerateController;
use App\Jobs\GenerateQuestionsJob;
use App\Models\AiJob;
use App\Models\User;
use App\Services\AiQuestionGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiJobTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'teacher'): User
    {
        return User::create([
            'id' => (string) Str::uuid(),
            'username' => 'u'.substr((string) Str::uuid(), 0, 6),
            'full_name' => 'U', 'role' => $role, 'active' => true,
        ]);
    }

    private function job(User $owner, string $status = 'queued'): AiJob
    {
        return AiJob::create([
            'id' => (string) Str::uuid(),
            'user_id' => $owner->id,
            'kind' => 'generate_questions',
            'status' => $status,
            'params' => ['target' => 'bank', 'count' => 2],
            'total' => 2,
        ]);
    }

    public function test_job_runs_generator_to_done_and_stores_result(): void
    {
        $owner = $this->user();
        $job = $this->job($owner);

        // Fake generator: no LLM/network, reports progress, returns a result.
        $fake = new class extends AiQuestionGenerator
        {
            public function generate(array $p, User $user, ?callable $onProgress = null): array
            {
                $onProgress && $onProgress(1, 2);
                $onProgress && $onProgress(2, 2);

                return ['created' => 2, 'target' => 'bank', 'imageCount' => 0,
                    'questions' => [['type' => 'essay', 'prompt' => 'Q1', 'hasImage' => false]]];
            }
        };

        (new GenerateQuestionsJob($job->id))->handle($fake);

        $job->refresh();
        $this->assertSame('done', $job->status);
        $this->assertSame(2, $job->result['created']);
        $this->assertSame(2, $job->progress);
    }

    public function test_job_records_failure_without_throwing(): void
    {
        $owner = $this->user();
        $job = $this->job($owner);

        $boom = new class extends AiQuestionGenerator
        {
            public function generate(array $p, User $user, ?callable $onProgress = null): array
            {
                throw new \RuntimeException('provider exploded');
            }
        };

        (new GenerateQuestionsJob($job->id))->handle($boom);

        $job->refresh();
        $this->assertSame('failed', $job->status);
        $this->assertStringContainsString('provider exploded', (string) $job->error);
    }

    public function test_job_status_is_scoped_to_owner(): void
    {
        $owner = $this->user();
        $job = $this->job($owner, 'done');
        $ctrl = new AiGenerateController();

        $asOwner = $ctrl->jobStatus($this->reqAs($owner), $job->id);
        $this->assertSame(200, $asOwner->getStatusCode());

        $asStranger = $ctrl->jobStatus($this->reqAs($this->user('teacher')), $job->id);
        $this->assertSame(404, $asStranger->getStatusCode());

        $asAdmin = $ctrl->jobStatus($this->reqAs($this->user('admin')), $job->id);
        $this->assertSame(200, $asAdmin->getStatusCode());
    }

    private function reqAs(User $u): Request
    {
        $r = Request::create('/x', 'GET');
        $r->attributes->set('authUser', $u);

        return $r;
    }
}
