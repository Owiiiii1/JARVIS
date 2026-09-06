<?php

namespace App\Jobs;

use App\Enums\StoredFileStatus;
use App\Jobs\Concerns\HandlesClassifiedAsyncFailure;
use App\Models\StoredFile;
use App\Services\Storage\StoredFileService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessStoredFileJob implements ShouldQueue
{
    use HandlesClassifiedAsyncFailure;
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public readonly int $storedFileId,
    ) {
        $this->onQueue((string) config('jarvis_storage.queue', 'default'));
        $this->tries = max(1, (int) config('reliability.job_tries', 3));
    }

    public function handle(StoredFileService $files): void
    {
        $file = StoredFile::query()->find($this->storedFileId);

        if ($file === null || $file->isDeleted()) {
            return;
        }

        $files->process($file);
    }

    public function failed(?Throwable $exception): void
    {
        $file = StoredFile::query()->find($this->storedFileId);

        if ($file === null || $file->status === StoredFileStatus::Ready || $file->isDeleted()) {
            return;
        }

        $failure = $this->classifyFailure($exception);
        $this->failureWriter()->failStoredFile($file, $failure);
        $this->failureWriter()->logFailure('stored file job failed', $failure, [
            'file_id' => $this->storedFileId,
        ]);
    }
}
