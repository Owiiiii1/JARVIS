<?php

namespace App\Services\Tools;

use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;

interface JarvisTool
{
    public function name(): string;

    public function definition(): ToolDefinition;

    public function meta(): ToolMeta;

    public function isAvailable(ToolExecutionContext $context): bool;

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult;
}
