<?php

return [

    'default' => [
        'max_context_tokens' => (int) env('AI_DEFAULT_CONTEXT_TOKENS', 32000),
        'reserved_output_tokens' => (int) env('AI_DEFAULT_OUTPUT_RESERVE', 2048),
    ],

    'providers' => [
        'gemini' => [
            'max_context_tokens' => 128000,
            'reserved_output_tokens' => 4096,
        ],
        'openai' => [
            'max_context_tokens' => 128000,
            'reserved_output_tokens' => 4096,
        ],
        'anthropic' => [
            'max_context_tokens' => 128000,
            'reserved_output_tokens' => 4096,
        ],
    ],

    'models' => [
        'gemini-2.5-flash' => ['max_context_tokens' => 128000, 'reserved_output_tokens' => 4096],
        'gemini-2.5-pro' => ['max_context_tokens' => 128000, 'reserved_output_tokens' => 4096],
        'gemini-2.0-flash' => ['max_context_tokens' => 128000, 'reserved_output_tokens' => 4096],
        'gemini-1.5-flash' => ['max_context_tokens' => 128000, 'reserved_output_tokens' => 4096],
        'gemini-1.5-pro' => ['max_context_tokens' => 128000, 'reserved_output_tokens' => 4096],
        'gpt-4o' => ['max_context_tokens' => 128000, 'reserved_output_tokens' => 4096],
        'gpt-4o-mini' => ['max_context_tokens' => 128000, 'reserved_output_tokens' => 4096],
        'gpt-4.1' => ['max_context_tokens' => 128000, 'reserved_output_tokens' => 4096],
        'gpt-4.1-mini' => ['max_context_tokens' => 128000, 'reserved_output_tokens' => 4096],
        'claude-sonnet-4-5' => ['max_context_tokens' => 128000, 'reserved_output_tokens' => 4096],
        'claude-3-5-sonnet' => ['max_context_tokens' => 128000, 'reserved_output_tokens' => 4096],
        'claude-3-5-haiku' => ['max_context_tokens' => 128000, 'reserved_output_tokens' => 4096],
        'claude-3-opus' => ['max_context_tokens' => 128000, 'reserved_output_tokens' => 4096],
    ],

];
