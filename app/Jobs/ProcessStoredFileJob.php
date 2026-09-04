<?php

namespace App\Jobs;

use App\Models\StoredFile;
use App\Services\Storage\StoredFileService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessStoredFileJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public readonly int $storedFileId,
    ) {
        $this->onQueue((string) config('jarvis_storage.queue', 'default'));
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
        try {
            Log::warning('stored file job failed', [
                'file_id' => $this->storedFileId,
                'error_class' => $exception ? $exception::class : null,
            ]);
        } catch (Throwable) {
        }
    }
}
