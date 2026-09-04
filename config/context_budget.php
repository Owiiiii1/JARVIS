<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Per-request context budgets (tokens, conservative estimates)
    |--------------------------------------------------------------------------
    |
    | Absolute token slices for one LLM request. If they exceed the model's
    | computed input budget they are scaled down from lowest priority first.
    | System/platform and the current user turn are never dropped.
    |
    */

    'safety_margin_tokens' => (int) env('CONTEXT_SAFETY_MARGIN_TOKENS', 512),

    'platform_prompt' => (int) env('CONTEXT_PLATFORM_PROMPT_TOKENS', 2800),

    'general_prompt' => (int) env('CONTEXT_GENERAL_PROMPT_TOKENS', 800),

    'current_turn' => (int) env('CONTEXT_CURRENT_TURN_TOKENS', 4000),

    'recent_messages' => (int) env('CONTEXT_RECENT_MESSAGES_TOKENS', 6000),

    'current_conversation_summary' => (int) env('CONTEXT_CURRENT_SUMMARY_TOKENS', 1200),

    'personal_memory' => (int) env('CONTEXT_PERSONAL_MEMORY_TOKENS', 800),

    'cross_chat_summaries' => (int) env('CONTEXT_CROSS_CHAT_TOKENS', 800),

    'projects' => (int) env('CONTEXT_PROJECTS_TOKENS', 400),

    'attachment_summaries' => (int) env('CONTEXT_ATTACHMENT_SUMMARY_TOKENS', 400),

    'storage_context' => (int) env('CONTEXT_STORAGE_TOKENS', 800),

    'storage_results' => (int) env('CONTEXT_STORAGE_RESULTS_TOKENS', 1500),

    'tool_results' => (int) env('CONTEXT_TOOL_RESULTS_TOKENS', 6000),

    'web_results' => (int) env('CONTEXT_WEB_RESULTS_TOKENS', 4000),

    'gmail_results' => (int) env('CONTEXT_GMAIL_RESULTS_TOKENS', 2000),

    'github_results' => (int) env('CONTEXT_GITHUB_RESULTS_TOKENS', 2000),

    'group_results' => (int) env('CONTEXT_GROUP_RESULTS_TOKENS', 1200),

    'emergency_minimum_recent' => (int) env('CONTEXT_EMERGENCY_RECENT', 2),

    'max_tool_rounds' => (int) env('CONTEXT_MAX_TOOL_ROUNDS', 8),

    'image_tokens' => (int) env('CONTEXT_IMAGE_TOKENS', 768),

    'summary_max_chars' => (int) env('CONTEXT_SUMMARY_MAX_CHARS', 4000),

    'summary_refresh_tokens' => (int) env('CONTEXT_SUMMARY_REFRESH_TOKENS', 2500),

    'unsummarized_message_cap' => (int) env('CONTEXT_UNSUMMARIZED_MESSAGE_CAP', 80),

];
