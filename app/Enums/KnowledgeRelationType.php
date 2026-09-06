<?php

namespace App\Enums;

enum KnowledgeRelationType: string
{
    case WorksOn = 'works_on';
    case WorksFor = 'works_for';
    case Uses = 'uses';
    case DependsOn = 'depends_on';
    case HasResource = 'has_resource';
    case MentionedIn = 'mentioned_in';
    case ClientOf = 'client_of';
    case RelatedTo = 'related_to';
    case Owns = 'owns';
    case ParticipatesIn = 'participates_in';

    public static function tryFromLoose(mixed $value): ?self
    {
        $raw = is_string($value) ? mb_strtolower(trim($value)) : '';

        $raw = str_replace([' ', '-'], '_', $raw);

        return match ($raw) {
            'works_on', 'workson', 'works' => self::WorksOn,
            'works_for', 'worksfor', 'employed_by' => self::WorksFor,
            'uses', 'use' => self::Uses,
            'depends_on', 'dependson', 'depends' => self::DependsOn,
            'has_resource', 'hasresource', 'has_file' => self::HasResource,
            'mentioned_in', 'mentionedin', 'mentioned' => self::MentionedIn,
            'client_of', 'clientof', 'client_contact_for' => self::ClientOf,
            'related_to', 'relatedto', 'related' => self::RelatedTo,
            'owns', 'owner_of' => self::Owns,
            'participates_in', 'participatesin', 'participates' => self::ParticipatesIn,
            default => self::tryFrom($raw),
        };
    }
}
