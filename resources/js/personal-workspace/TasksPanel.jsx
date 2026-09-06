import { workspaceRoute } from '@/personal-workspace/named';
import { Link } from '@inertiajs/react';
import { CheckSquare, Loader2, Plus } from 'lucide-react';
import { useEffect, useState } from 'react';

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

function formatLocal(iso, timezone) {
    if (!iso) {
        return 'без срока';
    }

    try {
        return new Intl.DateTimeFormat(undefined, {
            dateStyle: 'medium',
            timeStyle: 'short',
            timeZone: timezone || undefined,
        }).format(new Date(iso));
    } catch {
        return iso;
    }
}

function priorityLabel(value) {
    return { low: 'низкий', normal: 'обычный', high: 'высокий', urgent: 'срочный' }[value] || value;
}

function statusLabel(value) {
    return {
        open: 'Открыта',
        in_progress: 'В работе',
        completed: 'Выполнена',
        cancelled: 'Отменена',
    }[value] || value;
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

function TaskCard({ task, surface, busyId, canUseProjects, onStart, onComplete, onCancel, onEdit, onAddSubtask }) {
    const source = task.source_conversation;

    return (
        <li className="rounded-xl border border-white/10 bg-black/20 px-3 py-2">
            <p className="text-sm text-slate-100">{task.title}</p>
            {task.description ? <p className="mt-1 line-clamp-2 text-[11px] text-slate-500">{task.description}</p> : null}
            <p className="mt-1 text-[11px] text-slate-500">
                {formatLocal(task.due_at_local || task.due_at, task.timezone)}
                {' · '}
                {priorityLabel(task.priority)}
                {' · '}
                {statusLabel(task.status)}
                {task.reminder_count ? ` · напоминания: ${task.reminder_count}` : ''}
                {task.subtask_count ? ` · подзадачи: ${task.subtask_count}` : ''}
            </p>
            {task.project && canUseProjects ? (
                <p className="mt-1 text-[11px] text-slate-500">Проект: {task.project.name}</p>
            ) : null}
            {source ? (
                <p className="mt-1 text-[11px] text-slate-500">
                    Из разговора:{' '}
                    <Link href={workspaceRoute(surface, 'chats.show', source.id)} className="text-sky-300 hover:text-sky-200">
                        {source.title}
                    </Link>
                </p>
            ) : null}
            <div className="mt-2 flex flex-wrap gap-2">
                {task.startable ? (
                    <button type="button" disabled={busyId === task.id} onClick={() => onStart(task)} className="text-[11px] text-sky-300 hover:text-sky-200">
                        Начать
                    </button>
                ) : null}
                {task.completable ? (
                    <button type="button" disabled={busyId === task.id} onClick={() => onComplete(task)} className="text-[11px] text-emerald-300 hover:text-emerald-200">
                        Готово
                    </button>
                ) : null}
                {task.editable ? (
                    <button type="button" onClick={() => onEdit(task)} className="text-[11px] text-slate-300 hover:text-white">
                        Изменить
                    </button>
                ) : null}
                {task.cancellable ? (
                    <button type="button" disabled={busyId === task.id} onClick={() => onCancel(task)} className="text-[11px] text-rose-300 hover:text-rose-200">
                        Отменить
                    </button>
                ) : null}
                {task.editable ? (
                    <button type="button" onClick={() => onAddSubtask(task)} className="text-[11px] text-slate-300 hover:text-white">
                        Подзадача
                    </button>
                ) : null}
            </div>
        </li>
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
            if (caught.message?.includes('unfinished') || caught.message?.includes('subtasks')) {
                if (window.confirm('Есть незавершённые подзадачи. Завершить родительскую задачу всё равно?')) {
                    await mutate(workspaceRoute(surface, 'tasks.complete', task.id), {
                        method: 'POST',
                        body: JSON.stringify({ force: true }),
                    }, 'Не удалось завершить задачу.');
                }
            } else {
                setError(caught.message);
            }
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

    const section = (label, items) => (
        <section>
            <h3 className="mb-2 text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{label}</h3>
            {items.length === 0 ? (
                <p className="text-sm text-slate-500">Пока пусто.</p>
            ) : (
                <ul className="space-y-2">
                    {items.map((task) => (
                        <TaskCard
                            key={task.id}
                            task={task}
                            surface={surface}
                            busyId={busyId}
                            canUseProjects={canUseProjects}
                            onStart={(item) => act(item, 'start')}
                            onComplete={(item) => act(item, 'complete')}
                            onCancel={(item) => act(item, 'cancel')}
                            onEdit={setEditing}
                            onAddSubtask={(item) => {
                                setSubtaskFor(item);
                                setSubtaskTitle('');
                            }}
                        />
                    ))}
                </ul>
            )}
        </section>
    );

    return (
        <div className="fixed inset-0 z-50 flex items-start justify-end bg-black/50 p-3 sm:p-6" onClick={onClose}>
            <div
                className="flex h-full max-h-[92vh] w-full max-w-md flex-col overflow-hidden rounded-2xl border border-white/10 bg-[#101826] shadow-2xl"
                onClick={(event) => event.stopPropagation()}
            >
                <div className="flex items-center justify-between border-b border-white/10 px-4 py-3">
                    <div className="flex items-center gap-2 text-white">
                        <CheckSquare className="h-4 w-4" />
                        <h2 className="text-sm font-semibold">Задачи</h2>
                    </div>
                    <button type="button" onClick={onClose} className="text-sm text-slate-400 hover:text-white">
                        Закрыть
                    </button>
                </div>
                <div className="min-h-0 flex-1 space-y-5 overflow-y-auto px-4 py-4">
                    <div className="flex gap-2">
                        <button
                            type="button"
                            onClick={() => setCreating((value) => !value)}
                            className="inline-flex items-center gap-1 rounded-lg bg-white/10 px-3 py-1.5 text-xs text-white"
                        >
                            <Plus className="h-3.5 w-3.5" />
                            Новая задача
                        </button>
                        <button type="button" onClick={onCreateInChat} className="text-xs text-sky-300 hover:text-sky-200">
                            Через чат
                        </button>
                    </div>
                    {creating ? (
                        <form onSubmit={createTask} className="space-y-2 rounded-xl border border-white/10 p-3">
                            <input value={title} onChange={(event) => setTitle(event.target.value)} placeholder="Название" className="w-full rounded-lg border border-white/10 bg-black/30 px-3 py-2 text-sm text-white" required />
                            <textarea value={description} onChange={(event) => setDescription(event.target.value)} placeholder="Описание (необязательно)" className="w-full rounded-lg border border-white/10 bg-black/30 px-3 py-2 text-sm text-white" rows={2} />
                            <select value={priority} onChange={(event) => setPriority(event.target.value)} className="w-full rounded-lg border border-white/10 bg-black/30 px-3 py-2 text-sm text-white">
                                <option value="low">низкий</option>
                                <option value="normal">обычный</option>
                                <option value="high">высокий</option>
                                <option value="urgent">срочный</option>
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
                            <p className="text-xs text-slate-400">Изменить задачу</p>
                            <input value={editing.title} onChange={(event) => setEditing({ ...editing, title: event.target.value })} className="w-full rounded-lg border border-white/10 bg-black/30 px-3 py-2 text-sm text-white" />
                            <textarea value={editing.description || ''} onChange={(event) => setEditing({ ...editing, description: event.target.value })} className="w-full rounded-lg border border-white/10 bg-black/30 px-3 py-2 text-sm text-white" rows={2} />
                            <select value={editing.priority} onChange={(event) => setEditing({ ...editing, priority: event.target.value })} className="w-full rounded-lg border border-white/10 bg-black/30 px-3 py-2 text-sm text-white">
                                <option value="low">низкий</option>
                                <option value="normal">обычный</option>
                                <option value="high">высокий</option>
                                <option value="urgent">срочный</option>
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
                    {error ? <p className="text-xs text-rose-300">{error}</p> : null}
                    {loading ? (
                        <p className="flex items-center gap-2 text-sm text-slate-400">
                            <Loader2 className="h-4 w-4 animate-spin" />
                            Загрузка…
                        </p>
                    ) : (
                        <>
                            {section('Просрочено', overdue)}
                            {section('Сегодня', today)}
                            {section('Предстоящие', upcoming)}
                            {section('Без срока', undated)}
                            {section('Выполненные', completed)}
                        </>
                    )}
                </div>
            </div>
        </div>
    );
}
