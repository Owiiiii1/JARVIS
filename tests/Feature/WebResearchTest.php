<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\ToolExecutionLog;
use App\Models\User;
use App\Services\Ai\AiConfigurationResolver;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Conversations\ConversationContextBuilder;
use App\Services\Conversations\ConversationService;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\ToolRegistry;
use App\Services\Tools\WebResearch\SearchWebTool;
use App\Services\WebResearch\Contracts\WebSearchProvider;
use App\Services\WebResearch\DTO\WebSearchQuery;
use App\Services\WebResearch\WebSearchManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\TestCase;

class WebResearchTest extends TestCase
{
    use CleansTemporaryJarvisRecords;

    public function test_gemini_google_provider_is_bound_when_selected(): void
    {
        $this->app->forgetInstance(WebSearchProvider::class);
        $this->app->forgetInstance(WebSearchManager::class);

        $manager = app(WebSearchManager::class);
        $this->assertTrue($manager->isConfigured());
        $this->assertSame('gemini_google', $manager->providerName());
    }

    public function test_gemini_google_search_normalizes_grounding_to_web_source_reference(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => 'Grounded answer']]],
                    'groundingMetadata' => [
                        'groundingChunks' => [
                            ['web' => ['uri' => 'https://laravel.com/docs/queues', 'title' => 'Laravel queues']],
                            ['web' => ['uri' => 'https://php.net/manual', 'title' => 'PHP manual']],
                        ],
                        'groundingSupports' => [
                            [
                                'segment' => ['text' => 'Queue workers process jobs asynchronously.'],
                                'groundingChunkIndices' => [0],
                            ],
                        ],
                    ],
                ]],
            ], 200),
        ]);

        $this->app->forgetInstance(WebSearchProvider::class);
        $this->app->forgetInstance(WebSearchManager::class);

        $set = app(WebSearchManager::class)->search(new WebSearchQuery(
            query: 'laravel queues',
            maxResults: 5,
        ));

        $this->assertSame('gemini_google', $set->provider);
        $this->assertSame('https://laravel.com/docs/queues', $set->results[0]->url);
        $this->assertStringContainsString('Queue workers', $set->results[0]->snippet);
        $this->assertSame('https://laravel.com/docs/queues', $set->sources()[0]->url);
        Http::assertSentCount(1);
    }

    public function test_owner_search_web_succeeds_and_user_does_not_get_the_tool(): void
    {
        $chatId = null;
        $user = null;
        $owner = User::query()->where('role', UserRole::Owner)->first();
        $this->assertNotNull($owner);
        $title = 'jarvis-test-web-'.Str::lower(Str::random(8));

        try {
            Http::fake([
                'generativelanguage.googleapis.com/*' => Http::response([
                    'candidates' => [[
                        'groundingMetadata' => [
                            'groundingChunks' => [
                                ['web' => ['uri' => 'https://example.com/a', 'title' => 'Example']],
                            ],
                        ],
                    ]],
                ], 200),
            ]);

            $this->app->forgetInstance(WebSearchProvider::class);
            $this->app->forgetInstance(WebSearchManager::class);
            $this->app->forgetInstance(ToolRegistry::class);

            $conversation = app(ConversationService::class)->createPersonal($owner, $title);
            $chatId = $conversation->id;
            $registry = app(ToolRegistry::class);
            $ownerTools = array_map(
                static fn ($tool) => $tool->name,
                $registry->definitionsFor(new ToolExecutionContext($owner, $conversation)),
            );
            $this->assertContains(SearchWebTool::NAME, $ownerTools);

            $result = $registry->execute(
                new ToolCall('call_web_1', SearchWebTool::NAME, ['query' => 'example lookup']),
                new ToolExecutionContext($owner, $conversation),
            );
            $this->assertTrue($result->success);
            $this->assertArrayNotHasKey('provider', $result->payload);
            $this->assertSame('https://example.com/a', $result->payload['results'][0]['url']);
            $this->assertSame('https://example.com/a', $result->payload['sources'][0]['url']);

            $user = $this->createTemporaryUser();
            $userChat = app(ConversationService::class)->createPersonal($user, $title);
            $userTools = array_map(
                static fn ($tool) => $tool->name,
                $registry->definitionsFor(new ToolExecutionContext($user, $userChat)),
            );
            $this->assertNotContains(SearchWebTool::NAME, $userTools);
        } finally {
            if ($chatId !== null) {
                ToolExecutionLog::query()->where('conversation_id', $chatId)->delete();
                Message::query()->where('conversation_id', $chatId)->delete();
                Conversation::query()->whereKey($chatId)->delete();
            }
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_context_tells_the_model_to_use_search_web(): void
    {
        $owner = User::query()->where('role', UserRole::Owner)->first();
        $this->assertNotNull($owner);
        $title = 'jarvis-test-web-ctx-'.Str::lower(Str::random(8));
        $conversation = null;

        try {
            $conversation = app(ConversationService::class)->createPersonal($owner, $title);
            $configuration = app(AiConfigurationResolver::class)->resolveConversation($owner);
            $context = app(ConversationContextBuilder::class)->build(
                $owner,
                $conversation,
                $configuration,
                null,
                null,
                [new ToolDefinition(SearchWebTool::NAME, 'search', ['type' => 'OBJECT', 'properties' => []])],
            );

            $this->assertStringContainsString('Never say you cannot browse the internet', $context['system_prompt']);
            $this->assertStringContainsString('call search_web first', $context['system_prompt']);
        } finally {
            if ($conversation !== null) {
                Message::query()->where('conversation_id', $conversation->id)->delete();
                $conversation->delete();
            }
        }
    }
}
