<?php

namespace App\Jobs;

use App\Models\AiJob;
use App\Models\User;
use App\Services\AiQuestionGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Runs AI question generation off the web request. Not retried: generation
 * writes questions, so a retry would duplicate them. Failures are recorded on
 * the ai_jobs row (status=failed) rather than thrown, so the SPA can show them.
 */
class GenerateQuestionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public string $jobId) {}

    public function handle(AiQuestionGenerator $generator): void
    {
        $job = AiJob::find($this->jobId);
        if (! $job) {
            return;
        }
        $user = User::find($job->user_id);
        if (! $user) {
            $job->update(['status' => 'failed', 'error' => 'User not found.']);

            return;
        }

        $job->update(['status' => 'running']);
        try {
            $result = $generator->generate($job->params ?? [], $user, function (int $done, int $total) use ($job) {
                $job->update(['progress' => $done, 'total' => $total]);
            });
            $job->update([
                'status' => 'done',
                'result' => $result,
                'progress' => (int) $result['created'],
                'total' => (int) $result['created'],
            ]);
        } catch (\Throwable $e) {
            $job->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)]);
        }
    }

    public function failed(\Throwable $e): void
    {
        optional(AiJob::find($this->jobId))->update([
            'status' => 'failed',
            'error' => mb_substr($e->getMessage(), 0, 500),
        ]);
    }
}
