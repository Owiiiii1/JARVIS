import { workspaceRoute } from '@/personal-workspace/named';
import { Eye, Loader2, Plus } from 'lucide-react';
import { useEffect, useState } from 'react';

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

function formatLocal(iso) {
    if (!iso) {
        return '—';
    }

    try {
        return new Intl.DateTimeFormat(undefined, {
            dateStyle: 'medium',
            timeStyle: 'short',
        }).format(new Date(iso));
    } catch {
        return iso;
    }
}

function statusLabel(value) {
    return {
        active: 'Активен',
        paused: 'Пауза',
        completed: 'Завершён',
        failed: 'Ошибка',
        cancelled: 'Отменён',
    }[value] || value;
}

function healthLabel(value) {
    return {
        healthy: 'В порядке',
        waiting: 'Ожидает',
        blocked: 'Нужно переподключить',
        paused: 'Пауза',
        failed: 'Ошибка',
    }[value] || value;
}

function applyPanel(payload, setters) {
    setters.setItems(payload.items || []);
    setters.setRecent(payload.recent || []);
    setters.onCountChange?.(Number(payload.active_count || 0));
}

export default function WatchersPanel({ open, surface, refreshToken = 0, onClose, onCountChange, onDataChange, onCreateInChat }) {
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const [items, setItems] = useState([]);
    const [recent, setRecent] = useState([]);
    const [busyId, setBusyId] = useState(null);
    const [creating, setCreating] = useState(false);
    const [name, setName] = useState('');
    const [triggerType, setTriggerType] = useState('task_state');
    const [conditionType, setConditionType] = useState('overdue_by');
    const [reactionType, setReactionType] = useState('notify');
    const [mode, setMode] = useState('one_shot');
    const [taskId, setTaskId] = useState('');
    const [sourceFilter, setSourceFilter] = useState('');
    const [cooldown, setCooldown] = useState('3600');

    const setters = { setItems, setRecent, onCountChange };

    const load = () => {
        setLoading(true);
        setError('');

        return fetch(workspaceRoute(surface, 'watchers.index'), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then(async (response) => {
                const payload = await response.json().catch(() => ({}));
                if (!response.ok) {
                    throw new Error(payload.message || 'Не удалось загрузить автоматизации.');
                }
                applyPanel(payload, setters);
            })
            .catch((caught) => setError(caught.message || 'Не удалось загрузить автоматизации.'))
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

    const createWatcher = async (event) => {
        event.preventDefault();
        setBusyId('create');
        setError('');

        const source = {};
        if (sourceFilter.trim()) {
            if (triggerType === 'gmail_message') {
                source.query = sourceFilter.trim();
            } else if (triggerType === 'github_event') {
                source.repository = sourceFilter.trim();
            } else if (triggerType === 'calendar_event') {
                source.event_id = sourceFilter.trim();
            }
        }

        try {
            await mutate(workspaceRoute(surface, 'watchers.store'), {
                method: 'POST',
                body: JSON.stringify({
                    name,
                    trigger_type: triggerType,
                    condition_type: conditionType,
                    reaction_type: reactionType,
                    mode,
                    cooldown_seconds: Number(cooldown) || 0,
                    task_id: taskId ? Number(taskId) : undefined,
                    source,
                }),
            }, 'Не удалось создать автоматизацию.');
            setName('');
            setTaskId('');
            setSourceFilter('');
            setCreating(false);
        } catch (caught) {
            setError(caught.message || 'Не удалось создать автоматизацию.');
        } finally {
            setBusyId(null);
        }
    };

    const runAction = async (watcher, action, failure) => {
        setBusyId(watcher.id);
        setError('');
        try {
            await mutate(workspaceRoute(surface, `watchers.${action}`, watcher.id), {
                method: 'POST',
            }, failure);
        } catch (caught) {
            setError(caught.message || failure);
        } finally {
            setBusyId(null);
        }
    };

    if (!open) {
        return null;
    }

    return (
        <div className="fixed inset-0 z-50 flex justify-end bg-black/50" onClick={onClose}>
            <aside
                className="flex h-full w-full max-w-md flex-col border-l border-white/10 bg-slate-950 text-slate-100 shadow-2xl"
                onClick={(event) => event.stopPropagation()}
            >
                <div className="flex items-center justify-between border-b border-white/10 px-4 py-3">
                    <div className="flex items-center gap-2">
                        <Eye className="h-4 w-4 text-violet-300" />
                        <h2 className="text-sm font-semibold">Автоматизации</h2>
                    </div>
                    <button type="button" onClick={onClose} className="text-xs text-slate-400 hover:text-white">
                        Закрыть
                    </button>
                </div>

                <div className="flex-1 overflow-y-auto px-4 py-3">
                    {loading ? (
                        <p className="flex items-center gap-2 text-xs text-slate-400">
                            <Loader2 className="h-3 w-3 animate-spin" /> Загрузка…
                        </p>
                    ) : null}
                    {error ? <p className="mb-3 text-xs text-rose-300">{error}</p> : null}

                    <div className="mb-4 flex gap-2">
                        <button
                            type="button"
                            onClick={() => setCreating((current) => !current)}
                            className="inline-flex items-center gap-1 rounded-lg bg-white/10 px-2 py-1 text-xs text-slate-100 hover:bg-white/15"
                        >
                            <Plus className="h-3 w-3" />
                            Создать
                        </button>
                        <button type="button" onClick={onCreateInChat} className="text-xs text-sky-300 hover:text-sky-200">
                            Сказать в чате
                        </button>
                    </div>

                    {creating ? (
                        <form onSubmit={createWatcher} className="mb-4 space-y-2 rounded-xl border border-white/10 bg-black/20 p-3">
                            <input
                                value={name}
                                onChange={(event) => setName(event.target.value)}
                                placeholder="Название"
                                className="w-full rounded-lg border border-white/10 bg-black/30 px-2 py-1 text-sm"
                                required
                            />
                            <select value={triggerType} onChange={(event) => setTriggerType(event.target.value)} className="w-full rounded-lg border border-white/10 bg-black/30 px-2 py-1 text-xs">
                                <option value="task_state">Задача</option>
                                <option value="time_condition">Срок / время</option>
                                <option value="knowledge_event">Событие знания</option>
                                <option value="gmail_message">Gmail</option>
                                <option value="github_event">GitHub</option>
                                <option value="calendar_event">Календарь</option>
                            </select>
                            <select value={conditionType} onChange={(event) => setConditionType(event.target.value)} className="w-full rounded-lg border border-white/10 bg-black/30 px-2 py-1 text-xs">
                                <option value="overdue_by">Просрочена</option>
                                <option value="deadline_within">До дедлайна</option>
                                <option value="new_item">Новый элемент</option>
                                <option value="thread_received_reply">Ответ в треде</option>
                                <option value="github_new_commit">Новый commit</option>
                                <option value="calendar_changed">Календарь изменился</option>
                                <option value="entity_event_type">Событие сущности</option>
                            </select>
                            <select value={reactionType} onChange={(event) => setReactionType(event.target.value)} className="w-full rounded-lg border border-white/10 bg-black/30 px-2 py-1 text-xs">
                                <option value="notify">Уведомить</option>
                                <option value="create_reminder">Создать напоминание</option>
                                <option value="create_task">Создать задачу</option>
                                <option value="run_internal_analysis">Разобрать и сказать</option>
                                <option value="propose_action">Предложить действие</option>
                            </select>
                            <select value={mode} onChange={(event) => setMode(event.target.value)} className="w-full rounded-lg border border-white/10 bg-black/30 px-2 py-1 text-xs">
                                <option value="one_shot">Один раз</option>
                                <option value="recurring">Повторять</option>
                            </select>
                            <input
                                value={taskId}
                                onChange={(event) => setTaskId(event.target.value)}
                                placeholder="ID задачи (если следим за задачей)"
                                className="w-full rounded-lg border border-white/10 bg-black/30 px-2 py-1 text-xs"
                            />
                            <input
                                value={sourceFilter}
                                onChange={(event) => setSourceFilter(event.target.value)}
                                placeholder="Query / sender / repo / event id"
                                className="w-full rounded-lg border border-white/10 bg-black/30 px-2 py-1 text-xs"
                            />
                            <input
                                value={cooldown}
                                onChange={(event) => setCooldown(event.target.value)}
                                placeholder="Cooldown, сек"
                                className="w-full rounded-lg border border-white/10 bg-black/30 px-2 py-1 text-xs"
                            />
                            <button
                                type="submit"
                                disabled={busyId === 'create'}
                                className="rounded-lg bg-violet-500 px-3 py-1 text-xs font-medium text-white hover:bg-violet-400 disabled:opacity-50"
                            >
                                Сохранить
                            </button>
                        </form>
                    ) : null}

                    <ul className="space-y-2">
                        {items.map((watcher) => (
                            <li key={watcher.id} className="rounded-xl border border-white/10 bg-black/20 px-3 py-2">
                                <p className="text-sm text-slate-100">{watcher.name}</p>
                                <p className="mt-1 text-[11px] text-slate-500">
                                    {statusLabel(watcher.status)} · {healthLabel(watcher.health)} · {watcher.trigger_type} · {watcher.condition_type} → {watcher.reaction_type}
                                </p>
                                <p className="mt-1 text-[11px] text-slate-500">
                                    Проверка: {formatLocal(watcher.last_checked_at)} · Срабатывание: {formatLocal(watcher.last_triggered_at)}
                                </p>
                                <div className="mt-2 flex flex-wrap gap-2">
                                    {watcher.status === 'active' ? (
                                        <button type="button" disabled={busyId === watcher.id} onClick={() => runAction(watcher, 'pause', 'Не удалось поставить на паузу.')} className="text-[11px] text-slate-300 hover:text-white">
                                            Пауза
                                        </button>
                                    ) : null}
                                    {watcher.status === 'paused' ? (
                                        <button type="button" disabled={busyId === watcher.id} onClick={() => runAction(watcher, 'resume', 'Не удалось возобновить.')} className="text-[11px] text-sky-300 hover:text-sky-200">
                                            Возобновить
                                        </button>
                                    ) : null}
                                    {watcher.status === 'active' || watcher.status === 'paused' ? (
                                        <button type="button" disabled={busyId === watcher.id} onClick={() => runAction(watcher, 'cancel', 'Не удалось отменить.')} className="text-[11px] text-rose-300 hover:text-rose-200">
                                            Отменить
                                        </button>
                                    ) : null}
                                </div>
                            </li>
                        ))}
                    </ul>

                    {recent.length > 0 ? (
                        <div className="mt-6">
                            <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Недавние срабатывания</h3>
                            <ul className="space-y-2">
                                {recent.map((row) => (
                                    <li key={row.id} className="rounded-lg border border-white/5 bg-black/10 px-3 py-2 text-[11px] text-slate-400">
                                        {formatLocal(row.detected_at)} · {row.matched_condition} · {row.summary || row.reaction_status}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ) : null}
                </div>
            </aside>
        </div>
    );
}
