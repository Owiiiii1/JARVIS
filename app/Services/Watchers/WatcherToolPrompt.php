<?php

namespace App\Services\Watchers;

final class WatcherToolPrompt
{
    /**
     * @return list<string>
     */
    public static function toolNames(): array
    {
        return [
            'create_watcher',
            'list_watchers',
            'get_watcher',
            'update_watcher',
            'pause_watcher',
            'resume_watcher',
            'cancel_watcher',
            'list_watcher_occurrences',
            'run_watcher_now',
        ];
    }

    /**
     * @return list<string>
     */
    public static function lines(): array
    {
        return [
            'Reminders are a known time (“напомни завтра в 9”). Watchers are a future condition/event (“если завтра всё ещё не готово”). Tasks are work items. B.2 proactive suggestions are separate heuristics — do not recreate them as watchers.',
            'create_watcher only when the user explicitly asked to watch/notify when something happens. Resolve source to a stable id (task_id, knowledge_entity_id, project_id, thread_id, repository). If several GitHub repos or entities could match, ask — never guess.',
            'One-shot is the default for a single reply/deadline. Recurring is for “каждый раз / следи за коммитами”. Do not fire on historical inbox/repo/calendar items; baseline starts now unless the user asked to check already-existing items and keep watching.',
            'Reactions: notify / create_notification / create_reminder / create_task / run_internal_analysis / propose_action. Never send Gmail, write Calendar, or write GitHub from a watcher. External writes become a proposed action for later confirmation.',
            'list_watchers / get_watcher / list_watcher_occurrences read owned watchers. pause_watcher / resume_watcher / cancel_watcher / update_watcher / run_watcher_now mutate or check one owned watcher. run_watcher_now is a check only and does not bypass confirmation. Never pass user_id. Foreign watcher ids fail.',
        ];
    }
}
