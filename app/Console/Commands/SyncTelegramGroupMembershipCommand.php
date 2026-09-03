<?php

namespace App\Console\Commands;

use App\Services\Groups\TelegramGroupMembershipService;
use Illuminate\Console\Command;

class SyncTelegramGroupMembershipCommand extends Command
{
    protected $signature = 'jarvis:telegram-groups:sync-membership';

    protected $description = 'Archive Telegram groups where the bot is no longer a member';

    public function handle(TelegramGroupMembershipService $membership): int
    {
        $archived = $membership->archiveGroupsWhereBotHasLeft();

        $this->info('Archived '.$archived.' group(s) where the bot is no longer a member.');

        return self::SUCCESS;
    }
}
