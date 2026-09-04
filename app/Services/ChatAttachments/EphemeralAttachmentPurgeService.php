<?php

namespace App\Services\ChatAttachments;

use App\Enums\AttachmentRetentionClass;
use App\Enums\AttachmentSummaryStatus;
use App\Models\MessageAttachment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class EphemeralAttachmentPurgeService
{
    public function purgeBatch(): int
    {
        $purged = 0;
        $now = now();
        $hardBefore = now()->subDays(ChatAttachmentConfig::hardRetentionDays());

        $rows = MessageAttachment::query()
            ->where('retention_class', AttachmentRetentionClass::Ephemeral)
            ->whereNull('purged_at')
            ->where(function ($query) use ($now, $hardBefore): void {
                $query->where(function ($ready) use ($now): void {
                    $ready->where('summary_status', AttachmentSummaryStatus::Ready)
                        ->where('expires_at', '<=', $now);
                })->orWhere(function ($hard) use ($hardBefore): void {
                    $hard->where('created_at', '<=', $hardBefore);
                });
            })
            ->orderBy('id')
            ->limit(ChatAttachmentConfig::purgeBatch())
            ->get();

        foreach ($rows as $attachment) {
            if ($this->purgeOne($attachment)) {
                $purged++;
            }
        }

        return $purged;
    }

    public function purgeOne(MessageAttachment $attachment): bool
    {
        if ($attachment->isPurged()) {
            return false;
        }

        $hardExpired = $attachment->created_at !== null
            && $attachment->created_at->lte(now()->subDays(ChatAttachmentConfig::hardRetentionDays()));

        if ($attachment->summary_status !== AttachmentSummaryStatus::Ready && ! $hardExpired) {
            return false;
        }

        try {
            if ($attachment->storage_path !== '') {
                Storage::disk($attachment->storage_disk)->delete($attachment->storage_path);
            }

            $thumb = $attachment->thumbnailPath();

            if (is_string($thumb) && $thumb !== '') {
                Storage::disk($attachment->storage_disk)->delete($thumb);
            }

            $metadata = $attachment->metadata ?? [];
            unset($metadata['thumbnail_path']);

            $attachment->forceFill([
                'storage_path' => '',
                'purged_at' => now(),
                'metadata' => $metadata,
                'summary_status' => $attachment->summary_status === AttachmentSummaryStatus::Ready
                    ? AttachmentSummaryStatus::Ready
                    : AttachmentSummaryStatus::Failed,
            ])->save();

            try {
                Log::info('ephemeral attachment purged', [
                    'attachment_id' => $attachment->id,
                    'summary_status' => $attachment->summary_status?->value,
                ]);
            } catch (Throwable) {
            }

            return true;
        } catch (Throwable $exception) {
            $attachment->forceFill([
                'purge_failure_count' => (int) $attachment->purge_failure_count + 1,
            ])->save();

            try {
                Log::warning('ephemeral attachment purge failed', [
                    'attachment_id' => $attachment->id,
                    'error_class' => $exception::class,
                ]);
            } catch (Throwable) {
            }

            return false;
        }
    }
}
