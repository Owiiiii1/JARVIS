<?php

namespace App\Services\Knowledge;

final class KnowledgeToolPrompt
{
    /**
     * @return list<string>
     */
    public static function toolNames(): array
    {
        return [
            'search_knowledge',
            'get_entity',
            'get_entity_timeline',
            'get_entity_relationships',
            'list_related_entities',
            'remember_entity',
            'link_entities',
            'add_knowledge_note',
        ];
    }

    /**
     * @return list<string>
     */
    public static function lines(): array
    {
        return [
            'Knowledge Layer is a structured index of entities, relationships, and timeline events with provenance. It is not Memory and not a full graph dump.',
            'Memory is durable remembered facts. Knowledge is who/what exists, how they connect, and what happened, with sources.',
            'search_knowledge / get_entity / get_entity_timeline / get_entity_relationships / list_related_entities read the current user’s graph only. Never pass user_id. Foreign entity ids fail.',
            'Context may include a tiny knowledge slice when C.1 names an entity or project. Do not assume the full graph is present.',
            'remember_entity / link_entities / add_knowledge_note write only when the user explicitly asks to remember or link. Do not invent contacts or sensitive attributes. Do not merge or delete entities.',
            'If a knowledge result has conversation/task/project/file ids, use existing tools to fetch detail. Knowledge is an index, not a replacement for sources.',
        ];
    }
}
