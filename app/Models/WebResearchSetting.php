<?php

namespace App\Models;

use App\Enums\WebResearchProvider;
use Illuminate\Database\Eloquent\Model;

class WebResearchSetting extends Model
{
    protected $hidden = [
        'tavily_api_key',
    ];

    protected $fillable = [
        'enabled',
        'provider',
        'max_search_results',
        'max_searches_per_turn',
        'max_fetches_per_turn',
        'max_page_chars',
        'max_total_web_chars',
        'fetch_web_page_enabled',
        'timeout_seconds',
        'default_recency_days',
        'tavily_api_key',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'provider' => WebResearchProvider::class,
            'max_search_results' => 'integer',
            'max_searches_per_turn' => 'integer',
            'max_fetches_per_turn' => 'integer',
            'max_page_chars' => 'integer',
            'max_total_web_chars' => 'integer',
            'fetch_web_page_enabled' => 'boolean',
            'timeout_seconds' => 'integer',
            'default_recency_days' => 'integer',
            'tavily_api_key' => 'encrypted',
        ];
    }
}
