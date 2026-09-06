<?php

namespace App\Services\Tasks;

use App\Services\Tools\CancelTaskTool;
use App\Services\Tools\CompleteTaskTool;
use App\Services\Tools\CreateSubtaskTool;
use App\Services\Tools\CreateTaskTool;
use App\Services\Tools\GetTaskTool;
use App\Services\Tools\LinkTaskReminderTool;
use App\Services\Tools\ListTasksTool;
use App\Services\Tools\StartTaskTool;
use App\Services\Tools\UpdateTaskTool;

final class TaskToolPrompt
{
    /**
     * @return list<string>
     */
    public static function lines(): array
    {
        return [
            'Tasks are commitments (“what to do”). Reminders are “when to notify”. Watchers are “if/when a future condition happens”. Never treat a task as a reminder row.',
            'create_task only when the user explicitly asked to create/remember a task, or the message is an unambiguous commitment. Vague “надо бы…” is not enough — ask first. Do not spawn task spam.',
            'If several tasks could match (“закрой задачу про отчёт”), call list_tasks and ask which one. Never guess. Pass task_id when known. Never pass user_id.',
            'If the user refers to a just-created task with a pronoun (“напомни про неё”), use the trusted recent task id from working context or link_task_reminder. Do not invent ids. Do not search the whole task list first when one trusted recent task is unambiguous.',
            'list_tasks / get_task read owned tasks. update_task changes title, description, priority, due date, optional project (Owner only), optional calendar reference. start_task, complete_task, cancel_task are distinct. Complete means done; cancel means no longer needed.',
            'complete_task cancels future linked reminders for that task but keeps history. If the tool returns open_subtasks, ask the user to confirm completing anyway and retry with force=true.',
            'create_subtask adds a child of an owned parent. One level only. link_task_reminder creates or attaches a reminder to a task when the user wants to be notified.',
            'After success, confirm in natural language. Do not mention tool names.',
        ];
    }

    /**
     * @return list<string>
     */
    public static function toolNames(): array
    {
        return [
            CreateTaskTool::NAME,
            ListTasksTool::NAME,
            GetTaskTool::NAME,
            UpdateTaskTool::NAME,
            StartTaskTool::NAME,
            CompleteTaskTool::NAME,
            CancelTaskTool::NAME,
            CreateSubtaskTool::NAME,
            LinkTaskReminderTool::NAME,
        ];
    }
}
