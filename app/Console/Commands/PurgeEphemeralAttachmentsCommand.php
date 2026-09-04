<?php

namespace App\Console\Commands;

use App\Services\ChatAttachments\AttachmentVisionSummaryService;
use App\Services\ChatAttachments\EphemeralAttachmentPurgeService;
use Illuminate\Console\Command;

class PurgeEphemeralAttachmentsCommand extends Command
{
    protected $signature = 'jarvis:attachments:purge-ephemeral';

    protected $description = 'Queue pending visual summaries and delete expired ephemeral chat image bytes.';

    public function handle(
        AttachmentVisionSummaryService $summaries,
        EphemeralAttachmentPurgeService $purge,
    ): int {
        $queued = $summaries->enqueuePending(10);
        $count = $purge->purgeBatch();
        $this->info("Queued {$queued} visual summaries; purged {$count} ephemeral attachment file(s).");

        return self::SUCCESS;
    }
}
