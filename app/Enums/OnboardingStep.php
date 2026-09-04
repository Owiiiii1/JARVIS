<?php

namespace App\Enums;

enum OnboardingStep: string
{
    case AssistantName = 'assistant_name';
    case Personality = 'personality';
    case InteractionStyle = 'interaction_style';
    case AboutUser = 'about_user';
    case Summary = 'summary';
}
