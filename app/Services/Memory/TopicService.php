<?php

namespace App\Services\Memory;

use App\Enums\TopicStatus;
use App\Models\Message;
use App\Models\MessageTopicRelation;
use App\Models\Topic;
use App\Models\User;
use App\Services\Memory\DTO\TopicCandidate;
use Illuminate\Support\Carbon;

final class TopicService
{
    /**
     * @param  list<TopicCandidate>  $candidates
     * @param  list<int>  $allowedMessageIds
     */
    public function apply(User $user, array $candidates, array $allowedMessageIds): int
    {
        $applied = 0;

        foreach ($candidates as $candidate) {
            $normalized = MemoryKeyNormalizer::topicName($candidate->name);

            if ($normalized === '') {
                continue;
            }

            $topic = Topic::query()->firstOrNew([
                'user_id' => $user->id,
                'normalized_name' => $normalized,
            ]);

            $now = now();

            if (! $topic->exists) {
                $topic->fill([
                    'name' => mb_substr(trim($candidate->name), 0, 120),
                    'description' => $candidate->description,
                    'status' => TopicStatus::Active,
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                ]);
                $topic->save();
            } else {
                $topic->forceFill([
                    'last_seen_at' => $now,
                    'status' => TopicStatus::Active,
                ])->save();
            }

            $applied++;

            foreach ($candidate->messageIds as $messageId) {
                if (! in_array($messageId, $allowedMessageIds, true)) {
                    continue;
                }

                $owned = Message::query()
                    ->whereKey($messageId)
                    ->where('user_id', $user->id)
                    ->exists();

                if (! $owned) {
                    continue;
                }

                MessageTopicRelation::query()->firstOrCreate(
                    [
                        'message_id' => $messageId,
                        'topic_id' => $topic->id,
                    ],
                    [
                        'confidence' => $candidate->confidence,
                        'source' => 'analysis_ai',
                    ],
                );
            }
        }

        return $applied;
    }
}
