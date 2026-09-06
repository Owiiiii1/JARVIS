<?php

namespace App\Enums;

enum KnowledgeEntityType: string
{
    case Person = 'person';
    case Project = 'project';
    case Organization = 'organization';
    case Product = 'product';
    case Place = 'place';
    case Topic = 'topic';
    case System = 'system';
    case File = 'file';
    case Custom = 'custom';

    public static function tryFromLoose(mixed $value): ?self
    {
        $raw = is_string($value) ? mb_strtolower(trim($value)) : '';

        return match ($raw) {
            'person', 'people', 'contact' => self::Person,
            'project' => self::Project,
            'organization', 'org', 'company' => self::Organization,
            'product' => self::Product,
            'place', 'location' => self::Place,
            'topic' => self::Topic,
            'system', 'service', 'system/service' => self::System,
            'file', 'resource', 'file/resource' => self::File,
            'custom' => self::Custom,
            default => self::tryFrom($raw),
        };
    }
}
