import ConfirmDialog from '@/personal-workspace/components/ConfirmDialog';
import OverflowMenu from '@/personal-workspace/components/OverflowMenu';
import PanelSection from '@/personal-workspace/components/PanelSection';
import PanelShell from '@/personal-workspace/components/PanelShell';
import WorkspaceCard from '@/personal-workspace/components/WorkspaceCard';
import { workspaceRoute } from '@/personal-workspace/named';
import { Check, CheckSquare, ChevronDown, ChevronRight, Circle, Plus } from 'lucide-react';
import { useEffect, useState } from 'react';

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

function applyPanel(payload, setters) {
    setters.setToday(payload.today || []);
    setters.setOverdue(payload.overdue || []);
    setters.setUpcoming(payload.upcoming || []);
    setters.setUndated(payload.undated || []);
    setters.setCompleted(payload.completed || []);
    setters.setProjects(payload.projects || []);
    setters.setCanUseProjects(Boolean(payload.can_use_projects));
    setters.onCountChange?.(Number(payload.active_count || 0));
}

function subtaskListLabel(titles = [], count = 0) {
    const shown = titles.slice(0, 3).map((title) => `«${title}»`).join(', ');
    const rest = count - Math.min(titles.length, 3);

    return rest > 0 ? `${shown} и ещё ${rest}` : shown;
}

function SubtaskRow({ subtask, busyId, onComplete, onEdit, onCancel }) {
    const done = !subtask.open;

    return (
        <li className="flex items-center gap-2 py-1">
            {done ? (
                <Check className="h-3.5 w-3.5 shrink-0 text-emerald-400" />
            ) : (
                <Circle className="h-3.5 w-3.5 shrink-0 text-slate-500" />
            )}
            <span className={`min-w-0 flex-1 truncate text-xs ${done ? 'text-slate-500 line-through' : 'text-slate-200'}`}>
                {subtask.title}
            </span>
            {subtask.due_label && !done ? (
                <span className="shrink-0 text-[11px] text-slate-500">{subtask.due_label}</span>
            ) : null}
            {subtask.completable ? (
                <button
                    type="button"
                    disabled={busyId === subtask.id}
                    onClick={() => onComplete(subtask)}
                    className="shrink-0 rounded-lg px-2 py-0.5 text-[11px] text-emerald-300 hover:bg-emerald-500/10 disabled:opacity-40"
                >
                    Выполнить
                </button>
            ) : null}
            <OverflowMenu
                label="Действия с подзадачей"
                items={[
                    subtask.editable ? { label: 'Изменить', onSelect: () => onEdit(subtask) } : null,
                    subtask.cancellable ? { label: 'Отменить подзадачу', tone: 'danger', onSelect: () => onCancel(subtask) } : null,
                ].filter(Boolean)}
            />
        </li>
    );
}

function TaskCard({ task, canUseProjects, busyId, onStart, onComplete, onCancel, onEdit, onAddSubtask, onCompleteSubtask, onReopen }) {
    const [expanded, setExpanded] = useState(false);
    const subtasks = task.subtasks || [];
    const closed = task.status === 'completed' || task.status === 'cancelled';

    const actions = [
        task.startable ? { label: 'Начать работу', onSelect: () => onStart(task) } : null,
        task.editable ? { label: 'Изменить', onSelect: () => onEdit(task) } : null,
        task.editable ? { label: 'Добавить подзадачу', onSelect: () => onAddSubtask(task) } : null,
        task.cancellable ? { label: 'Отменить задачу', tone: 'danger', onSelect: () => onCancel(task) } : null,
        task.reopenable ? { label: 'Вернуть в работу', onSelect: () => onReopen(task) } : null,
    ].filter(Boolean);

    return (
        <WorkspaceCard
            title={task.title}
            secondary={closed ? task.completed_label || task.status_label : task.schedule_label}
            secondaryTone={task.is_overdue ? 'alert' : 'muted'}
            meta={[
                canUseProjects && task.project ? task.project.name : null,
                task.parent_label,
                task.priority_label,
                task.state_label,
                subtasks.length > 0 ? task.subtask_progress_label : null,
            ]}
            muted={closed}
            primaryAction={
                task.completable
                    ? { label: 'Выполнить', disabled: busyId === task.id, onSelect: () => onComplete(task) }
                    : null
            }
            actions={actions}
        >
            {subtasks.length > 0 ? (
                <div className="mt-2">
                    <button
                        type="button"
                        aria-expanded={expanded}
                        onClick={() => setExpanded((value) => !value)}
                        className="flex items-center gap-1 rounded-lg py-1 text-[11px] text-slate-400 hover:text-white"
                    >
                        {expanded ? <ChevronDown className="h-3.5 w-3.5" /> : <ChevronRight className="h-3.5 w-3.5" />}
                        Подзадачи
                    </button>
                    {expanded ? (
                        <ul className="border-t border-white/5 pt-1">
                            {subtasks.map((subtask) => (
                                <SubtaskRow
                                    key={subtask.id}
                                    subtask={subtask}
                                    busyId={busyId}
                                    onComplete={onCompleteSubtask}
                                    onEdit={onEdit}
                                    onCancel={onCancel}
                                />
                            ))}
                        </ul>
                    ) : null}
                </div>
            ) : null}
        </WorkspaceCard>
    );
}

export default function TasksPanel({ open, surface, refreshToken = 0, onClose, onCountChange, onDataChange, onCreateInChat }) {
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const [today, setToday] = useState([]);
    const [overdue, setOverdue] = useState([]);
    const [upcoming, setUpcoming] = useState([]);
    const [undated, setUndated] = useState([]);
    const [completed, setCompleted] = useState([]);
    const [projects, setProjects] = useState([]);
    const [canUseProjects, setCanUseProjects] = useState(false);
    const [busyId, setBusyId] = useState(null);
    const [creating, setCreating] = useState(false);
    const [title, setTitle] = useState('');
    const [description, setDescription] = useState('');
    const [priority, setPriority] = useState('normal');
    const [dueAt, setDueAt] = useState('');
    const [projectId, setProjectId] = useState('');
    const [editing, setEditing] = useState(null);
    const [subtaskFor, setSubtaskFor] = useState(null);
    const [subtaskTitle, setSubtaskTitle] = useState('');
    const [confirming, setConfirming] = useState(null);

    const setters = { setToday, setOverdue, setUpcoming, setUndated, setCompleted, setProjects, setCanUseProjects, onCountChange };

    const load = () => {
        setLoading(true);
        setError('');

        return fetch(workspaceRoute(surface, 'tasks.index'), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then(async (response) => {
                const payload = await response.json().catch(() => ({}));
                if (!response.ok) {
                    throw new Error(payload.message || 'Не удалось загрузить задачи.');
                }
                applyPanel(payload, setters);
            })
            .catch((caught) => setError(caught.message || 'Не удалось загрузить задачи.'))
            .finally(() => setLoading(false));
    };

    useEffect(() => {
        if (!open) {
            return undefined;
        }

        load();

        return undefined;
    }, [open, surface, refreshToken]);

    const mutate = async (url, options, failure) => {
        const response = await fetch(url, {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            ...options,
        });
        const payload = await response.json().catch(() => ({}));

        if (!response.ok) {
            throw new Error(payload.message || failure);
        }

        applyPanel(payload, setters);
        onDataChange?.();
        return payload;
    };

    const createTask = async (event) => {
        event.preventDefault();
        setBusyId('create');
        setError('');

        try {
            await mutate(workspaceRoute(surface, 'tasks.store'), {
                method: 'POST',
                body: JSON.stringify({
                    title,
                    description: description || null,
                    priority,
                    due_at_local: dueAt || null,
                    project_id: projectId || null,
                }),
            }, 'Не удалось создать задачу.');
            setTitle('');
            setDescription('');
            setDueAt('');
            setProjectId('');
            setCreating(false);
        } catch (caught) {
            setError(caught.message);
        } finally {
            setBusyId(null);
        }
    };

    const saveEdit = async (event) => {
        event.preventDefault();
        if (!editing) {
            return;
        }

        setBusyId(editing.id);
        try {
            await mutate(workspaceRoute(surface, 'tasks.update', editing.id), {
                method: 'PATCH',
                body: JSON.stringify({
                    title: editing.title,
                    description: editing.description,
                    priority: editing.priority,
                    due_at_local: editing.due_at_local || null,
                    project_id: editing.project?.id || null,
                }),
            }, 'Не удалось сохранить задачу.');
            setEditing(null);
        } catch (caught) {
            setError(caught.message);
        } finally {
            setBusyId(null);
        }
    };

    const act = async (task, name, extra = {}) => {
        setBusyId(task.id);
        setError('');
        try {
            await mutate(workspaceRoute(surface, `tasks.${name}`, task.id), {
                method: 'POST',
                body: JSON.stringify(extra),
            }, 'Не удалось обновить задачу.');
        } catch (caught) {
            setError(caught.message);
        } finally {
            setBusyId(null);
        }
    };

    const completeTask = (task) => {
        if ((task.open_subtask_count || 0) > 0) {
            setConfirming(task);

            return;
        }

        act(task, 'complete');
    };

    /**
     * "Выполнить всё" closes the open subtasks first, so the parent never completes while its
     * own checklist is still open.
     */
    const completeWithSubtasks = async () => {
        const task = confirming;

        if (!task) {
            return;
        }

        setBusyId(task.id);
        setError('');

        try {
            for (const subtask of (task.subtasks || []).filter((row) => row.open)) {
                await mutate(workspaceRoute(surface, 'tasks.complete', subtask.id), {
                    method: 'POST',
                    body: JSON.stringify({}),
                }, 'Не удалось завершить подзадачу.');
            }

            await mutate(workspaceRoute(surface, 'tasks.complete', task.id), {
                method: 'POST',
                body: JSON.stringify({ force: true }),
            }, 'Не удалось завершить задачу.');
            setConfirming(null);
        } catch (caught) {
            setError(caught.message);
            setConfirming(null);
        } finally {
            setBusyId(null);
        }
    };

    const addSubtask = async (event) => {
        event.preventDefault();
        if (!subtaskFor) {
            return;
        }

        setBusyId(subtaskFor.id);
        try {
            await mutate(workspaceRoute(surface, 'tasks.subtasks.store', subtaskFor.id), {
                method: 'POST',
                body: JSON.stringify({ title: subtaskTitle }),
            }, 'Не удалось добавить подзадачу.');
            setSubtaskFor(null);
            setSubtaskTitle('');
        } catch (caught) {
            setError(caught.message);
        } finally {
            setBusyId(null);
        }
    };

    if (!open) {
        return null;
    }

    const section = (label, items, empty) => (
        <PanelSection title={label} count={items.length} empty={empty}>
            {items.map((task) => (
                <TaskCard
                    key={task.id}
                    task={task}
                    canUseProjects={canUseProjects}
                    busyId={busyId}
                    onStart={(item) => act(item, 'start')}
                    onComplete={completeTask}
                    onCompleteSubtask={(item) => act(item, 'complete')}
                    onCancel={(item) => act(item, 'cancel')}
                    onEdit={setEditing}
                    onAddSubtask={(item) => {
                        setSubtaskFor(item);
                        setSubtaskTitle('');
                    }}
                    onReopen={(item) => act(item, 'reopen')}
                />
            ))}
        </PanelSection>
    );

    const toolbar = (
        <div className="flex gap-2">
            <button
                type="button"
                onClick={() => setCreating((value) => !value)}
                className="inline-flex items-center gap-1 rounded-lg bg-white/10 px-3 py-1.5 text-xs text-white hover:bg-white/20"
            >
                <Plus className="h-3.5 w-3.5" />
                Новая задача
            </button>
            <button type="button" onClick={onCreateInChat} className="rounded-lg px-2 py-1.5 text-xs text-sky-300 hover:text-sky-200">
                Через чат
            </button>
        </div>
    );

    return (
        <>
            <PanelShell icon={CheckSquare} iconClassName="text-white" title="Задачи" onClose={onClose} toolbar={toolbar} loading={loading} error={error}>
                {creating ? (
                    <form onSubmit={createTask} className="space-y-2 rounded-xl border border-white/10 p-3">
                        <input value={title} onChange={(event) => setTitle(event.target.value)} placeholder="Название" className="w-full rounded-lg border border-white/10 bg-black/30 px-3 py-2 text-sm text-white" required />
                        <textarea value={description} onChange={(event) => setDescription(event.target.value)} placeholder="Описание (необязательно)" className="w-full rounded-lg border border-white/10 bg-black/30 px-3 py-2 text-sm text-white" rows={2} />
                        <select value={priority} onChange={(event) => setPriority(event.target.value)} className="w-full rounded-lg border border-white/10 bg-black/30 px-3 py-2 text-sm text-white">
                            <option value="low">Низкий приоритет</option>
                            <option value="normal">Обычный приоритет</option>
                            <option value="high">Высокий приоритет</option>
                            <option value="urgent">Срочно</option>
                        </select>
                        <input type="datetime-local" value={dueAt} onChange={(event) => setDueAt(event.target.value)} className="w-full rounded-lg border border-white/10 bg-black/30 px-3 py-2 text-sm text-white" />
                        {canUseProjects ? (
                            <select value={projectId} onChange={(event) => setProjectId(event.target.value)} className="w-full rounded-lg border border-white/10 bg-black/30 px-3 py-2 text-sm text-white">
                                <option value="">Без проекта</option>
                                {projects.map((project) => (
                                    <option key={project.id} value={project.id}>{project.name}</option>
                                ))}
                            </select>
                        ) : null}
                        <button type="submit" disabled={busyId === 'create'} className="rounded-lg bg-sky-500/90 px-3 py-1.5 text-xs font-medium text-white">
                            Создать
                        </button>
                    </form>
                ) : null}
                {editing ? (
                    <form onSubmit={saveEdit} className="space-y-2 rounded-xl border border-sky-500/30 p-3">
                        <p className="text-xs text-slate-400">Изменить: {editing.title}</p>
                        <input value={editing.title} onChange={(event) => setEditing({ ...editing, title: event.target.value })} className="w-full rounded-lg border border-white/10 bg-black/30 px-3 py-2 text-sm text-white" />
                        <textarea value={editing.description || ''} onChange={(event) => setEditing({ ...editing, description: event.target.value })} className="w-full rounded-lg border border-white/10 bg-black/30 px-3 py-2 text-sm text-white" rows={2} />
                        <select value={editing.priority} onChange={(event) => setEditing({ ...editing, priority: event.target.value })} className="w-full rounded-lg border border-white/10 bg-black/30 px-3 py-2 text-sm text-white">
                            <option value="low">Низкий приоритет</option>
                            <option value="normal">Обычный приоритет</option>
                            <option value="high">Высокий приоритет</option>
                            <option value="urgent">Срочно</option>
                        </select>
                        <div className="flex gap-2">
                            <button type="submit" className="rounded-lg bg-sky-500/90 px-3 py-1.5 text-xs text-white">Сохранить</button>
                            <button type="button" onClick={() => setEditing(null)} className="text-xs text-slate-400">Отмена</button>
                        </div>
                    </form>
                ) : null}
                {subtaskFor ? (
                    <form onSubmit={addSubtask} className="space-y-2 rounded-xl border border-white/10 p-3">
                        <p className="text-xs text-slate-400">Подзадача для: {subtaskFor.title}</p>
                        <input value={subtaskTitle} onChange={(event) => setSubtaskTitle(event.target.value)} className="w-full rounded-lg border border-white/10 bg-black/30 px-3 py-2 text-sm text-white" required />
                        <div className="flex gap-2">
                            <button type="submit" className="rounded-lg bg-sky-500/90 px-3 py-1.5 text-xs text-white">Добавить</button>
                            <button type="button" onClick={() => setSubtaskFor(null)} className="text-xs text-slate-400">Отмена</button>
                        </div>
                    </form>
                ) : null}
                {section('Просрочено', overdue, 'Просроченного нет.')}
                {section('Сегодня', today, 'На сегодня ничего не запланировано.')}
                {section('Дальше', upcoming, 'Ближайших сроков нет.')}
                {section('Без срока', undated, 'Задач без срока нет.')}
                {section('Выполненные', completed, 'Пока ничего не выполнено.')}
            </PanelShell>
            <ConfirmDialog
                open={Boolean(confirming)}
                title="Сначала закроем подзадачи?"
                body={
                    confirming ? (
                        <>
                            <p>
                                {`У задачи «${confirming.title}» осталось незавершённых подзадач: ${confirming.open_subtask_count}.`}
                            </p>
                            <p className="mt-1 text-slate-400">
                                {subtaskListLabel(confirming.open_subtask_titles, confirming.open_subtask_count)}
                            </p>
                            <p className="mt-2">Отметить их выполненными вместе с задачей?</p>
                        </>
                    ) : null
                }
                confirmLabel="Выполнить всё"
                cancelLabel="Вернуться"
                busy={busyId === confirming?.id}
                onConfirm={completeWithSubtasks}
                onCancel={() => setConfirming(null)}
            />
        </>
    );
}
