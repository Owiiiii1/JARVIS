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
            'Reminders are a known time when the user themselves must act (“напомни мне завтра в 9 проверить почту”). Watchers are a future condition/event or a recurring Jarvis-performed check (“если завтра всё ещё не готово”, “жди письмо от школы”, “проверяй каждое утро почту и сообщай, что нового”). Tasks are work items. B.2 proactive suggestions are separate heuristics — do not recreate them as watchers.',
            'create_watcher when the user asked Jarvis to watch, check, or report — not when they asked to be reminded to do it themselves. Resolve source to a stable id (task_id, knowledge_entity_id, project_id, thread_id, repository) when the source is specific. If several GitHub repos or entities could match, ask — never guess. For “если эта задача завтра всё ещё будет открыта / still open tomorrow” use trigger_type=task_state, condition_type=status_equals (aliases: still_open, open_tomorrow), condition.status=open, condition.hours=24, and the trusted recent task_id — never overdue_by unless the task has a due date.',
            'Three Gmail intents. Reminder: “Напомни мне в 9 проверить почту.” Digest: “Каждое утро рассказывай, что нового в почте” / “Каждый день в 9 присылай сводку Gmail” → trigger_type=gmail_message, source.digest=true, source.query=in:inbox, source.schedule.kind=daily_local, mode=recurring. Event: “Жди письмо от школы.” / “Следи за письмами от @example.com.” / “Когда Marco ответит, сообщи мне.” / “Сообщай о каждом письме от бухгалтерии.” → Gmail recurring event watcher, NOT a digest and NOT knowledge_event. Pass source.sender, source.senders, or source.sender_domains. Do not invent a raw Gmail query if structured senders/domains are enough. mode=recurring unless the user clearly wants only the first reply.',
            'Never hijack event monitoring into a morning digest. “следи за письмами от X” is an event watcher. Only “каждое утро / каждый день в X / утренняя сводка / присылай сводку” is a digest. Default local digest time is 08:00 when the user said “каждое утро” without a clock. Confirm using the returned description — never say “создан watcher”.',
            'If the user adds another sender (“и от академии тоже”, “и ещё следи за письмами от example.com”) and a trusted recent Gmail event watcher exists, update that watcher. Do not only search Gmail. If several watchers could match, ask. Never guess ids.',
            'You may say Gmail monitoring is active only when create_watcher succeeded AND payload.kind is gmail_event or gmail_digest (trigger_type=gmail_message). If create_watcher failed, say the returned message and do not claim monitoring exists. If another non-Gmail watcher succeeded, do not describe it as Gmail monitoring. Do not fall back to knowledge_event, a reminder, or a project watcher for mail.',
            'The same Jarvis-checks-vs-user-acts split applies to Calendar and GitHub. “Каждое утро проверь календарь и расскажи, что сегодня” → calendar watcher. “Проверяй каждый день GitHub и сообщай о новых commit” → github watcher. “Напомни мне проверить календарь” → reminder.',
            'One-shot is the default for a single reply/deadline on tasks. Recurring is for “каждый раз / следи / жди письма / каждое утро проверяй”. Do not fire on historical inbox/repo/calendar items; the first check only establishes a baseline of already-seen items. Later checks report only what appeared after that cursor. If the user also asked to check whether matching mail is already in the inbox, call search_gmail first, then create the watcher. Never dump Gmail message ids or thread ids to the user.',
            'If create_watcher returns google_not_connected, say that Jarvis can do this after Gmail is connected. If it returns gmail_scope_required, say Gmail access must be granted. If it returns gmail_filter_required, ask for a sender or domain. Never say Jarvis cannot check Gmail when Gmail tools or watchers are available.',
            'Reactions: notify / create_notification / create_reminder / create_task / run_internal_analysis / propose_action. Never send Gmail, write Calendar, or write GitHub from a watcher. Never mark Gmail messages read, archive, label, or reply. External writes become a proposed action for later confirmation.',
            'list_watchers / get_watcher / list_watcher_occurrences read owned watchers. pause_watcher / resume_watcher / cancel_watcher / update_watcher / run_watcher_now mutate or check one owned watcher. run_watcher_now is a check only and does not bypass confirmation. Never pass user_id or integration_account_id. Foreign watcher ids fail.',
        ];
    }
}
