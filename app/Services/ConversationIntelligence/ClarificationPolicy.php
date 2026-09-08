<?php

namespace App\Services\ConversationIntelligence;

use App\Enums\ReferenceOutcome;
use App\Enums\ToolOperationClass;

final class ClarificationPolicy
{
    public function reason(
        WorkingContext $working,
        ?ToolOperationClass $operation = null,
        bool $requiredFieldMissing = false,
        bool $externalDestinationAmbiguous = false,
        bool $contextContradiction = false,
    ): ?string {
        $mutation = $operation?->isMutation() ?? false;

        if ($contextContradiction) {
            return 'context_contradiction';
        }

        if ($mutation && $externalDestinationAmbiguous) {
            return 'external_destination_ambiguous';
        }

        if ($mutation && $requiredFieldMissing) {
            return 'missing_required_field';
        }

        if ($mutation && $working->referenceOutcome === ReferenceOutcome::Ambiguous) {
            return $operation === ToolOperationClass::Destructive
                ? 'destructive_ambiguous'
                : 'write_target_ambiguous';
        }

        if ($mutation && $working->referenceOutcome === ReferenceOutcome::Unresolved && $working->lastImportantObject === null) {
            return 'insufficient_confidence';
        }

        return null;
    }

    public function shouldClarifyRelativeDate(string $text): bool
    {
        return false;
    }
}
