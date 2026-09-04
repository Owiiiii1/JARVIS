<?php

namespace App\Services\Tools\Storage;

use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Storage\StoredFileConfig;
use App\Services\Tools\ToolExecutionContext;

final class GetStorageFileTool extends StorageTool
{
    public const NAME = 'get_storage_file';

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Loads metadata, structural summary, and a bounded excerpt for one Storage file. Never dumps a huge file. Use public_id from list/search or the current-turn attached file list.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'file_id' => [
                        'type' => 'STRING',
                        'description' => 'Storage file public_id UUID.',
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

        $excerpt = $this->files->previewText($file);
        $max = StoredFileConfig::maxExcerptChars();

        if (mb_strlen($excerpt) > $max) {
            $excerpt = mb_substr($excerpt, 0, $max);
        }

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            'file' => $this->search->fileCard($file),
            'excerpt' => $excerpt,
            'truncated' => $file->extracted_chars > mb_strlen($excerpt),
        ]);
    }
}
