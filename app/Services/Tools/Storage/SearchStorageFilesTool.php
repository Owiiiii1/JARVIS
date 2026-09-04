<?php

namespace App\Services\Tools\Storage;

use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Storage\StoredFileConfig;
use App\Services\Tools\ToolExecutionContext;

final class SearchStorageFilesTool extends StorageTool
{
    public const NAME = 'search_storage_files';

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Searches persistent Storage filenames, summaries, and types. Returns candidate files, not contents. Then use get_storage_file or search_storage_file_contents.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'query' => [
                        'type' => 'STRING',
                        'description' => 'Filename, type, or summary keywords.',
                    ],
                    'max_results' => [
                        'type' => 'INTEGER',
                        'description' => 'Optional cap.',
                    ],
                ],
                'required' => ['query'],
            ],
        );
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $query = trim((string) ($call->arguments['query'] ?? ''));

        if ($query === '') {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => 'invalid_arguments',
            ]);
        }

        $limit = isset($call->arguments['max_results']) ? (int) $call->arguments['max_results'] : StoredFileConfig::searchResultLimit();
        $files = $this->search->searchFiles($context->user, $query, null, $limit);

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            'count' => count($files),
            'truncated' => count($files) >= StoredFileConfig::searchResultLimit(),
            'files' => $files,
        ]);
    }
}
