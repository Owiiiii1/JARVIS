<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Ai\ToolCallFingerprint;
use App\Services\Ai\ToolLoopProgress;
use PHPUnit\Framework\TestCase;

class ToolLoopProgressTest extends TestCase
{
    public function test_identical_storage_reads_are_reused_and_stop_the_loop(): void
    {
        $progress = new ToolLoopProgress;
        $call = new ToolCall('1', 'read_storage_file_chunks', [
            'file_id' => 'aaa',
            'start' => 0,
            'user_id' => 99,
        ]);
        $result = ToolResult::success('1', 'read_storage_file_chunks', [
            'success' => true,
            'file_id' => 'aaa',
            'chunks' => [['index' => 0, 'text' => 'G1 A90']],
        ]);

        $progress->remember($call, $result);
        $this->assertSame($result, $progress->reusedResult(new ToolCall('2', 'read_storage_file_chunks', [
            'file_id' => 'aaa',
            'start' => 0,
            'integration_account_id' => 'ignored',
        ])));

        $progress->finishRound([
            ['call' => $call, 'result' => $result, 'reused' => false],
        ]);
        $this->assertFalse($progress->shouldStop(2));

        $progress->finishRound([
            ['call' => $call, 'result' => $result, 'reused' => true],
        ]);
        $progress->finishRound([
            ['call' => $call, 'result' => $result, 'reused' => true],
        ]);

        $this->assertTrue($progress->shouldStop(2));
        $this->assertTrue($progress->repetitionDetected);
    }

    public function test_argument_fingerprint_ignores_ownership_fields(): void
    {
        $left = new ToolCall('a', 'get_storage_file', ['file_id' => 'f1', 'user_id' => 1]);
        $right = new ToolCall('b', 'get_storage_file', ['file_id' => 'f1', 'authorized' => true]);

        $this->assertSame(ToolCallFingerprint::forCall($left), ToolCallFingerprint::forCall($right));
    }

    public function test_result_fingerprint_ignores_chunk_text(): void
    {
        $left = ToolResult::success('1', 'read_storage_file_chunks', [
            'file_id' => 'f',
            'chunks' => [['index' => 0, 'text' => 'one']],
        ]);
        $right = ToolResult::success('2', 'read_storage_file_chunks', [
            'file_id' => 'f',
            'chunks' => [['index' => 0, 'text' => 'two']],
        ]);

        $this->assertSame(ToolCallFingerprint::forResult($left), ToolCallFingerprint::forResult($right));
    }
}
