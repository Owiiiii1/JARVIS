<?php

namespace App\Enums;

enum KnowledgeSourceType: string
{
    case Memory = 'memory';
    case Summary = 'summary';
    case Conversation = 'conversation';
    case Message = 'message';
    case Task = 'task';
    case Reminder = 'reminder';
    case Project = 'project';
    case StoredFile = 'stored_file';
    case Gmail = 'gmail';
    case Calendar = 'calendar';
    case Github = 'github';
    case TelegramGroup = 'telegram_group';
    case Manual = 'manual';
    case ToolResult = 'tool_result';
}
