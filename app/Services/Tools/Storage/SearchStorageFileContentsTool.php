<?php

namespace App\Services\Tools\Storage;

use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Storage\StoredFileConfig;
use App\Services\Tools\ToolExecutionContext;

final class SearchStorageFileContentsTool extends StorageTool
{
    public const NAME = 'search_storage_file_contents';

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Searches the extracted text chunks of one Storage file. Use for logs and large sources: find the error, then optionally read_storage_file_chunks around it.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'file_id' => [
                        'type' => 'STRING',
                        'description' => 'Storage file public_id UUID.',
                    ],
                    'query' => [
                        'type' => 'STRING',
                        'description' => 'Substring or keywords to find in the file.',
                    ],
                    'max_results' => [
                        'type' => 'INTEGER',
                        'description' => 'Optional cap on matching chunks.',
                    ],
                ],
                'required' => ['file_id', 'query'],
            ],
        );
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $id = trim((string) ($call->arguments['file_id'] ?? ''));
        $query = trim((string) ($call->arguments['query'] ?? ''));

        if ($id === '' || $query === '') {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => 'invalid_arguments',
            ]);
        }

        $file = $this->files->findOwnedByPublicId($context->user, $id);

        if ($file === null) {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => 'not_found',
            ]);
        }

        $limit = isset($call->arguments['max_results']) ? (int) $call->arguments['max_results'] : StoredFileConfig::maxChunksPerToolResult();
        $hits = $this->search->searchContents($context->user, $file, $query, $limit);

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            'file_id' => $file->public_id,
            'count' => count($hits),
            'truncated' => count($hits) >= StoredFileConfig::maxChunksPerToolResult(),
            'matches' => $hits,
        ]);
    }
}
