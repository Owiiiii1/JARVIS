<?php

namespace App\Services\Knowledge;

use App\Enums\KnowledgeEntityStatus;
use App\Enums\KnowledgeEntityType;
use App\Models\KnowledgeEntity;
use App\Models\KnowledgeEntityAlias;
use App\Models\User;
use Illuminate\Support\Collection;

final class KnowledgeEntityResolver
{
    /**
     * @param  list<mixed>  $aliases
     */
    public function findMatch(
        User $user,
        KnowledgeEntityType $type,
        string $normalizedName,
        array $aliases = [],
        ?int $projectId = null,
        ?string $externalRef = null,
        float $confidence = 0.95,
    ): ?KnowledgeEntity {
        if ($externalRef !== null && $externalRef !== '') {
            $byRef = KnowledgeEntity::query()
                ->where('user_id', $user->id)
                ->where('type', $type)
                ->where('metadata->external_ref', $externalRef)
                ->orderByDesc('id')
                ->first();

            if ($byRef !== null) {
                return $byRef;
            }
        }

        if ($type === KnowledgeEntityType::Project && $projectId !== null) {
            $byProject = KnowledgeEntity::query()
                ->where('user_id', $user->id)
                ->where('type', KnowledgeEntityType::Project)
                ->where('project_id', $projectId)
                ->orderByDesc('id')
                ->first();

            if ($byProject !== null) {
                return $byProject;
            }
        }

        $aliasNames = [$normalizedName];

        foreach ($aliases as $alias) {
            if (! is_string($alias) || trim($alias) === '') {
                continue;
            }

            $aliasNames[] = KnowledgeNameNormalizer::name($alias);
        }

        $aliasNames = array_values(array_unique(array_filter($aliasNames)));

        $viaAlias = KnowledgeEntityAlias::query()
            ->where('user_id', $user->id)
            ->whereIn('normalized_alias', $aliasNames)
            ->whereHas('entity', function ($query) use ($user, $type): void {
                $query->where('user_id', $user->id)->where('type', $type);
            })
            ->with('entity')
            ->orderByDesc('id')
            ->first();

        if ($viaAlias?->entity !== null) {
            return $viaAlias->entity;
        }

        $sameName = KnowledgeEntity::query()
            ->where('user_id', $user->id)
            ->where('type', $type)
            ->where('normalized_name', $normalizedName)
            ->where('status', '!=', KnowledgeEntityStatus::Inactive)
            ->orderByDesc('id')
            ->get();

        if ($sameName->count() === 1) {
            return $sameName->first();
        }

        if ($sameName->count() > 1 && $projectId !== null) {
            $linked = $sameName->firstWhere('project_id', $projectId);

            if ($linked instanceof KnowledgeEntity) {
                return $linked;
            }
        }

        if ($sameName->count() === 1) {
            return $sameName->first();
        }

        if ($sameName->isEmpty()) {
            return null;
        }

        if ($type === KnowledgeEntityType::Person && ! KnowledgeConfidence::isHigh($confidence)) {
            return null;
        }

        if ($type !== KnowledgeEntityType::Person && KnowledgeConfidence::isHigh($confidence)) {
            return $sameName->first();
        }

        return null;
    }

    public function findByNormalizedName(User $user, string $normalizedName): ?KnowledgeEntity
    {
        $viaAlias = KnowledgeEntityAlias::query()
            ->where('user_id', $user->id)
            ->where('normalized_alias', $normalizedName)
            ->whereHas('entity', function ($query) use ($user): void {
                $query->where('user_id', $user->id);
            })
            ->with('entity')
            ->orderByDesc('id')
            ->first();

        if ($viaAlias?->entity !== null) {
            return $viaAlias->entity;
        }

        return KnowledgeEntity::query()
            ->where('user_id', $user->id)
            ->where('normalized_name', $normalizedName)
            ->where('status', '!=', KnowledgeEntityStatus::Inactive)
            ->orderByDesc('id')
            ->first();
    }

    public function search(User $user, string $query, ?KnowledgeEntityType $type = null, int $limit = 8): Collection
    {
        $normalized = KnowledgeNameNormalizer::name($query);
        $like = '%'.KnowledgeNameNormalizer::escapeLike($normalized).'%';
        $limit = max(1, min(20, $limit));

        $ids = KnowledgeEntityAlias::query()
            ->where('user_id', $user->id)
            ->where('normalized_alias', 'like', $like)
            ->limit($limit)
            ->pluck('knowledge_entity_id');

        $builder = KnowledgeEntity::query()
            ->where('user_id', $user->id)
            ->where('status', '!=', KnowledgeEntityStatus::Inactive)
            ->where(function ($inner) use ($like, $ids): void {
                $inner->where('normalized_name', 'like', $like)
                    ->orWhere('name', 'like', $like)
                    ->orWhereIn('id', $ids);
            });

        if ($type !== null) {
            $builder->where('type', $type);
        }

        return $builder
            ->withCount(['aliases', 'sources'])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
