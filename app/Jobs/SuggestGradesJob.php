<?php

namespace App\Jobs;

use App\Models\AiJob;
use App\Models\ExamSubmission;
use App\Services\AssistedGrading;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Runs AI grading suggestions off the web request. AssistedGrading both
 * persists suggestions onto the submission and returns them; we also store the
 * returned map on the ai_jobs row so the SPA can pick it up from the poll.
 * Not retried (the AI runs cost credits and the result is advisory).
 */
class SuggestGradesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public string $jobId) {}

    public function handle(): void
    {
        $job = AiJob::find($this->jobId);
        if (! $job) {
            return;
        }
        $job->update(['status' => 'running']);
        try {
            $p = $job->params ?? [];
            $sub = ExamSubmission::find($p['submissionId'] ?? '');
            if (! $sub) {
                $job->update(['status' => 'failed', 'error' => 'Submission not found.']);

                return;
            }
            $suggestions = AssistedGrading::forSubmission($sub, (int) ($p['runs'] ?? 3));
            $job->update(['status' => 'done', 'result' => $suggestions]);
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
