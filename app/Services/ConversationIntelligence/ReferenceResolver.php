<?php

namespace App\Services\ConversationIntelligence;

use App\Enums\ReferenceOutcome;

final class ReferenceResolver
{
    /**
     * @param  list<ConversationalEntity>  $entities
     * @return array{outcome: ReferenceOutcome, entity: ?ConversationalEntity, unresolved: bool}
     */
    public function resolve(string $currentText, array $entities, ?ConversationalEntity $lastImportant = null, bool $incomplete = false): array
    {
        $normalized = $this->normalize($currentText);

        if ($normalized === '') {
            return ['outcome' => ReferenceOutcome::None, 'entity' => null, 'unresolved' => false];
        }

        $hasReference = $this->hasDeictic($normalized);

        if (! $hasReference && ! $incomplete) {
            return ['outcome' => ReferenceOutcome::None, 'entity' => null, 'unresolved' => false];
        }

        $typed = $this->preferredType($normalized);
        $candidates = $this->candidates($entities, $lastImportant, $typed);

        if (count($candidates) === 1) {
            $outcome = $incomplete ? ReferenceOutcome::IncompleteResolved : ReferenceOutcome::Resolved;

            return ['outcome' => $outcome, 'entity' => $candidates[0], 'unresolved' => false];
        }

        if (count($candidates) > 1) {
            return ['outcome' => ReferenceOutcome::Ambiguous, 'entity' => null, 'unresolved' => true];
        }

        if ($hasReference || $incomplete) {
            return ['outcome' => ReferenceOutcome::Unresolved, 'entity' => $lastImportant, 'unresolved' => $lastImportant === null];
        }

        return ['outcome' => ReferenceOutcome::None, 'entity' => null, 'unresolved' => false];
    }

    public function isPronominalQuery(?string $query): bool
    {
        $normalized = $this->normalize((string) $query);

        if ($normalized === '') {
            return false;
        }

        if (preg_match('/^(её|ее|его|их|это|эта|этот|эти|ней|ним|нём|нем|неё|нее|том|ту|того|той)$/u', $normalized) === 1) {
            return true;
        }

        return $this->hasDeictic($normalized) && mb_strlen($normalized) < 28;
    }

    public function looksIncomplete(string $text): bool
    {
        $trimmed = trim($text);

        if ($trimmed === '') {
            return false;
        }

        if (preg_match('/(\.\.\.|…)$/u', $trimmed) === 1) {
            return true;
        }

        $normalized = $this->normalize($trimmed);
        $words = preg_split('/\s+/u', $normalized) ?: [];

        if (count($words) <= 6 && preg_match('/^(а |и |ну |так |ещё |еще )/u', $normalized) === 1) {
            return true;
        }

        return (bool) preg_match('/^(а если тогда|и потом его туда|ну а с этим что|а это\??|и потом\??|а если завтра)/u', $normalized);
    }

    public function hasDeictic(string $normalized): bool
    {
        $normalized = $this->normalize($normalized);
        $tokens = [
            'это', 'этот', 'эта', 'эти', 'он', 'она', 'они', 'его', 'её', 'ее', 'их',
            'него', 'неё', 'нее', 'ней', 'ним', 'нём', 'нем',
            'там', 'туда', 'той', 'тот', 'те', 'предыдущий', 'предыдущая', 'предыдущее',
            'последний', 'последняя', 'последнее', 'ней',
        ];

        foreach ($tokens as $token) {
            if (preg_match('/(^|[^\p{L}\p{N}])'.preg_quote($token, '/').'([^\p{L}\p{N}]|$)/u', $normalized) === 1) {
                return true;
            }
        }

        return (bool) preg_match('/эта задача|этот проект|это письмо|тот проект|по этому вопросу|с ним|с ней/u', $normalized);
    }

    public function preferredType(string $normalized): ?string
    {
        if (preg_match('/задач|task/u', $normalized) === 1) {
            return 'task';
        }

        if (preg_match('/проект|project/u', $normalized) === 1) {
            return 'project';
        }

        if (preg_match('/письм|email|gmail|mail/u', $normalized) === 1) {
            return 'email';
        }

        if (preg_match('/напомин|reminder/u', $normalized) === 1) {
            return 'reminder';
        }

        if (preg_match('/встреч|event|календар/u', $normalized) === 1) {
            return 'calendar_event';
        }

        if (preg_match('/файл|file/u', $normalized) === 1) {
            return 'file';
        }

        return null;
    }

    /**
     * @param  list<ConversationalEntity>  $entities
     * @return list<ConversationalEntity>
     */
    private function candidates(array $entities, ?ConversationalEntity $lastImportant, ?string $type): array
    {
        $pool = [];

        foreach ($entities as $entity) {
            if ($entity->expired) {
                continue;
            }

            if ($type !== null && $entity->type !== $type) {
                continue;
            }

            $key = $entity->type.':'.(string) ($entity->id ?? $entity->label);
            $pool[$key] = $entity;
        }

        if ($lastImportant !== null && ! $lastImportant->expired && ($type === null || $lastImportant->type === $type)) {
            $key = $lastImportant->type.':'.(string) ($lastImportant->id ?? $lastImportant->label);
            $pool[$key] = $lastImportant;
        }

        return array_values($pool);
    }

    private function normalize(string $text): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? $text));
    }
}
