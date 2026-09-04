<?php

namespace App\Services\Tools;

use App\Enums\ToolConfirmationDecision;
use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;

final class ToolConfirmationPolicy
{
    public function decide(JarvisTool $tool, ToolExecutionContext $context, ToolCall $call): ToolConfirmationDecision
    {
        // Model-supplied authorized/confirmation/user_id/integration_account_id
        // are never a source of rights and are ignored here.
        $ignored = $call->arguments['authorized'] ?? $call->arguments['confirmation'] ?? null;
        unset($ignored);

        $operation = $tool->meta()->operation;

        if ($context->bypassConfirmation === true) {
            return ToolConfirmationDecision::Allowed;
        }

        if ($operation === ToolOperationClass::Read) {
            return ToolConfirmationDecision::Allowed;
        }

        if ($tool->meta()->alwaysConfirm === true) {
            return ToolConfirmationDecision::ConfirmationRequired;
        }

        if ($operation === ToolOperationClass::Destructive) {
            return ToolConfirmationDecision::ConfirmationRequired;
        }

        if ($tool->meta()->provider === null) {
            return ToolConfirmationDecision::Allowed;
        }

        if ($context->explicitUserCommand === true) {
            return ToolConfirmationDecision::Allowed;
        }

        return ToolConfirmationDecision::ConfirmationRequired;
    }
}
