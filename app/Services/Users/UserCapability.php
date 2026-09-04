<?php

namespace App\Services\Users;

final class UserCapability
{
    public const CHAT = 'chat';

    public const MEMORY = 'memory';

    public const TELEGRAM_DM = 'telegram_dm';

    public const REMINDERS = 'reminders';

    public const CABINET = 'cabinet';

    public const PROFILE = 'profile';

    public const ADMIN = 'admin';

    public const USERS_ADMIN = 'users_admin';

    public const INTEGRATIONS_ADMIN = 'integrations_admin';

    public const TELEGRAM_GROUPS = 'telegram_groups';

    public const GROUP_ANALYSIS = 'group_analysis';

    public const PROJECTS = 'projects';

    public const GMAIL = 'gmail';

    public const GOOGLE_CALENDAR = 'google_calendar';

    public const GITHUB = 'github';

    public const STORAGE = 'storage';

    public const VOICE = 'voice';

    public const IMPERSONATION = 'impersonation';

    public const SYSTEM_AI_SETTINGS = 'system_ai_settings';

    /**
     * @return list<string>
     */
    public static function forRegularUser(): array
    {
        return [
            self::CHAT,
            self::MEMORY,
            self::TELEGRAM_DM,
            self::REMINDERS,
            self::CABINET,
            self::PROFILE,
        ];
    }
}
