<?php

namespace App\Services\Tools\WebResearch;

use App\Enums\ToolOperationClass;
use App\Services\Context\TurnBudgetTracker;
use App\Services\Tools\JarvisTool;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\ToolMeta;
use App\Services\Users\UserCapability;
use App\Services\WebResearch\WebPageFetchService;
use App\Services\WebResearch\WebResearchSettingsService;
use App\Services\WebResearch\WebSearchManager;

abstract class WebResearchTool implements JarvisTool
{
    public function __construct(
        protected readonly WebSearchManager $search,
        protected readonly WebPageFetchService $pages,
        protected readonly WebResearchSettingsService $webResearch,
    ) {}

    public function isAvailable(ToolExecutionContext $context): bool
    {
        return $context->user->isActive()
            && $context->user->canUseCapability(UserCapability::WEB_RESEARCH);
    }

    public function meta(): ToolMeta
    {
        return new ToolMeta(
            capability: UserCapability::WEB_RESEARCH,
            operation: ToolOperationClass::Read,
            provider: 'web',
        );
    }

    protected function budgets(ToolExecutionContext $context): TurnBudgetTracker
    {
        return $context->budgets;
    }
}
