<?php

namespace App\Services\Memory;

final class ProfilePromptBuilder
{
    /**
     * @param  list<string>  $memories
     */
    public function build(array $memories): string
    {
        $lines = [
            'Task: write a compact stable user profile from high-confidence personal memories. JSON only.',
            'Schema: {"summary":"string"}',
            'The profile is a short characterization, not a dump of every memory.',
            'Do not invent facts. Do not include secrets or credentials. 3-8 sentences max.',
            'Memories:',
        ];

        foreach ($memories as $memory) {
            $lines[] = '- '.$memory;
        }

        return implode("\n", $lines);
    }
}
