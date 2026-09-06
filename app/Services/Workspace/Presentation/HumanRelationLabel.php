<?php

namespace App\Services\Workspace\Presentation;

use App\Enums\KnowledgeRelationType;

/**
 * Relation types are graph edges; the user reads a sentence about two named things.
 */
final class HumanRelationLabel
{
    public static function sentence(
        KnowledgeRelationType $type,
        string $source,
        string $target,
        ?string $role = null,
    ): string {
        $source = trim($source);
        $target = trim($target);
        $role = is_string($role) ? trim($role) : null;

        if ($type === KnowledgeRelationType::WorksOn && $role !== null && $role !== '') {
            return $source.' отвечает за '.$role.' в '.$target;
        }

        return $source.' '.self::predicate($type).' '.$target;
    }

    public static function predicate(KnowledgeRelationType $type): string
    {
        return match ($type) {
            KnowledgeRelationType::WorksOn => 'работает над',
            KnowledgeRelationType::WorksFor => 'работает в',
            KnowledgeRelationType::Uses => 'использует',
            KnowledgeRelationType::DependsOn => 'зависит от',
            KnowledgeRelationType::HasResource => 'связан с материалами',
            KnowledgeRelationType::MentionedIn => 'упоминается в',
            KnowledgeRelationType::ClientOf => 'клиент',
            KnowledgeRelationType::RelatedTo => 'связан с',
            KnowledgeRelationType::Owns => 'отвечает за',
            KnowledgeRelationType::ParticipatesIn => 'участвует в',
            KnowledgeRelationType::WaitingOn => 'ждёт',
            KnowledgeRelationType::CommittedTo => 'обещал',
        };
    }

    /**
     * The blocker wording used when work is held up by something else.
     */
    public static function dependency(string $target): string
    {
        return 'Работа зависит от завершения «'.trim($target).'»';
    }
}
