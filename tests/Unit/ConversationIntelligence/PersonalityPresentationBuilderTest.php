<?php

namespace Tests\Unit\ConversationIntelligence;

use App\Enums\OnboardingStatus;
use App\Enums\ReferenceOutcome;
use App\Enums\TopicContinuityMode;
use App\Models\UserAssistantProfile;
use App\Services\Assistant\AssistantProfileService;
use App\Services\ConversationIntelligence\PersonalityPresentationBuilder;
use App\Services\ConversationIntelligence\WorkingContext;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\TestCase;

class PersonalityPresentationBuilderTest extends TestCase
{
    use CleansTemporaryJarvisRecords;

    public function test_web_voice_and_telegram_share_the_same_identity_source(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            UserAssistantProfile::query()->create([
                'user_id' => $user->id,
                'assistant_name' => 'Jarvis',
                'personality' => 'спокойный',
                'interaction_style' => 'прямой',
                'about_user' => 'инженер',
                'onboarding_status' => OnboardingStatus::Completed,
            ]);
            $builder = new PersonalityPresentationBuilder(app(AssistantProfileService::class));
            $web = $builder->build($user);
            $voice = $builder->build($user, null, 'Keep spoken replies brief.');
            $telegram = $builder->build($user);

            $this->assertStringContainsString('Name: Jarvis', $web);
            $this->assertStringContainsString('Name: Jarvis', $voice);
            $this->assertStringContainsString('Name: Jarvis', $telegram);
            $this->assertStringContainsString('Web, Voice, and Telegram', $web);
            $this->assertStringContainsString('Voice presentation hint only', $voice);
            $this->assertStringNotContainsString('Voice presentation hint only', $web);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_temporary_style_is_conversation_scoped(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            UserAssistantProfile::query()->create([
                'user_id' => $user->id,
                'assistant_name' => 'Jarvis',
                'onboarding_status' => OnboardingStatus::Completed,
            ]);
            $working = new WorkingContext(
                topicMode: TopicContinuityMode::Continue,
                referenceOutcome: ReferenceOutcome::None,
                continuitySource: 'recent_tail',
                temporaryStyle: 'short',
            );
            $prompt = (new PersonalityPresentationBuilder(app(AssistantProfileService::class)))->build($user, $working);

            $this->assertStringContainsString('Temporary conversation style (not a profile write)', $prompt);
            $this->assertStringContainsString('answer briefly', $prompt);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }
}
