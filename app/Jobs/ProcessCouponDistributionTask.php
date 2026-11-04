<?php

namespace App\Jobs;

use App\Models\CouponDistributionTask;
use App\Models\CouponTemplate;
use App\Services\CouponAudienceService;
use App\Services\CouponWalletService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessCouponDistributionTask implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    const BATCH_SIZE = 200;

    public $taskId;
    public $tries = 3;
    public $timeout = 120;

    public function __construct(int $taskId)
    {
        $this->taskId = $taskId;
        $this->onQueue('default');
    }

    public function handle(CouponWalletService $service, CouponAudienceService $audience)
    {
        $task = CouponDistributionTask::find($this->taskId);
        if (!$task || $task->status === 'cancelled' || in_array($task->status, ['completed', 'partial'])) return;

        $task->status = 'running';
        $task->attempts = (int)$task->attempts + 1;
        $task->heartbeat_at = time();
        $task->last_error = null;
        $task->save();

        $template = CouponTemplate::find($task->template_id);
        if (!$template) throw new \RuntimeException('优惠券模板不存在');

        $users = $audience->query($task->filters)
            ->where('id', '>', (int)$task->current_cursor)
            ->orderBy('id')
            ->limit(self::BATCH_SIZE)
            ->get();

        if ($users->isEmpty()) {
            $this->finish($task);
            return;
        }

        $success = 0;
        $skipped = 0;
        $failed = 0;
        $failedIds = (array)$task->failed_user_ids;

        foreach ($users as $user) {
            $task->refresh();
            if ($task->status === 'cancelled') return;
            try {
                $result = $service->issueWithStatus($template, $user, 'distribution_task', (string)$task->id);
                if ($result['coupon']) $success++;
                else $skipped++;
            } catch (\Throwable $e) {
                report($e);
                $failed++;
                $failedIds[] = (int)$user->id;
                $task->last_error = mb_substr($e->getMessage(), 0, 1000);
            }
        }

        $task->refresh();
        if ($task->status === 'cancelled') return;
        $task->success_count += $success;
        $task->skipped_count += $skipped;
        $task->failed_count += $failed;
        $task->processed_count += $users->count();
        $task->current_cursor = (int)$users->last()->id;
        $task->completed_batches += 1;
        $task->failed_user_ids = array_values(array_unique($failedIds));
        $task->heartbeat_at = time();
        $task->save();

        if ($users->count() < self::BATCH_SIZE || $task->processed_count >= $task->estimated_count) {
            $this->finish($task);
            return;
        }

        self::dispatch($task->id)->onQueue('default');
    }

    private function finish(CouponDistributionTask $task)
    {
        $task->refresh();
        if ($task->status === 'cancelled') return;
        $task->status = $task->failed_count > 0 ? 'partial' : 'completed';
        $task->completed_at = time();
        $task->heartbeat_at = time();
        $task->save();
    }

    public function failed(\Throwable $exception)
    {
        $task = CouponDistributionTask::find($this->taskId);
        if (!$task || $task->status === 'cancelled') return;
        $task->status = 'failed';
        $task->last_error = mb_substr($exception->getMessage(), 0, 1000);
        $task->heartbeat_at = time();
        $task->save();
    }
}
