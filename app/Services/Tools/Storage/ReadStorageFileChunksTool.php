<?php

namespace App\Services\Tools\Storage;

use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Storage\StoredFileConfig;
use App\Services\Tools\ToolExecutionContext;

final class ReadStorageFileChunksTool extends StorageTool
{
    public const NAME = 'read_storage_file_chunks';

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Reads a bounded sequential range of Storage file chunks. Use after search_storage_file_contents to inspect lines around a match. Never request the whole file.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'file_id' => [
                        'type' => 'STRING',
                        'description' => 'Storage file public_id UUID.',
                    ],
                    'start_chunk' => [
                        'type' => 'INTEGER',
                        'description' => 'First chunk index, 0-based.',
                    ],
                    'count' => [
                        'type' => 'INTEGER',
                        'description' => 'How many chunks. Core caps this.',
                    ],
                ],
                'required' => ['file_id'],
            ],
        );
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $id = trim((string) ($call->arguments['file_id'] ?? ''));

        if ($id === '') {
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

        $start = isset($call->arguments['start_chunk']) ? (int) $call->arguments['start_chunk'] : 0;
        $count = isset($call->arguments['count']) ? (int) $call->arguments['count'] : 2;
        $chunks = $this->search->readChunks($context->user, $file, $start, $count);

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            'file_id' => $file->public_id,
            'chunk_count' => $file->chunk_count,
            'count' => count($chunks),
            'truncated' => count($chunks) >= StoredFileConfig::maxChunksPerToolResult(),
            'chunks' => $chunks,
        ]);
    }
}
