<?php

use App\Enums\WebResearchProvider;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_research_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(true);
            $table->string('provider', 32);
            $table->unsignedTinyInteger('max_search_results');
            $table->unsignedTinyInteger('max_searches_per_turn');
            $table->unsignedTinyInteger('max_fetches_per_turn');
            $table->unsignedInteger('max_page_chars');
            $table->unsignedInteger('max_total_web_chars');
            $table->boolean('fetch_web_page_enabled')->default(true);
            $table->unsignedTinyInteger('timeout_seconds');
            $table->unsignedSmallInteger('default_recency_days')->nullable();
            $table->text('tavily_api_key')->nullable();
            $table->timestamps();
        });

        $provider = WebResearchProvider::normalize(config('web_research.provider'));
        $recency = (int) config('web_research.default_recency_days', 0);

        DB::table('web_research_settings')->insert([
            'enabled' => (bool) config('web_research.enabled', true) && $provider !== WebResearchProvider::Disabled,
            'provider' => $provider->value,
            'max_search_results' => max(1, (int) config('web_research.max_search_results', 8)),
            'max_searches_per_turn' => max(1, (int) config('web_research.max_searches_per_turn', 2)),
            'max_fetches_per_turn' => max(0, (int) config('web_research.max_fetches_per_turn', 4)),
            'max_page_chars' => max(500, (int) config('web_research.max_page_chars', 8000)),
            'max_total_web_chars' => max(1000, (int) config('web_research.max_total_web_chars', 18000)),
            'fetch_web_page_enabled' => (bool) config('web_research.fetch_web_page_enabled', true),
            'timeout_seconds' => max(2, (int) config('web_research.timeout', 12)),
            'default_recency_days' => $recency > 0 ? $recency : null,
            'tavily_api_key' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('web_research_settings');
    }
};
