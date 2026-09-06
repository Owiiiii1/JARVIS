import { workspaceRoute } from '@/personal-workspace/named';
import { currentPushState, enableReminderPush, notificationPermission, pushSupported } from '@/personal-workspace/reminderPush';
import { Link } from '@inertiajs/react';
import { Bell, Check, Loader2, Pencil, Plus, X } from 'lucide-react';
import { useEffect, useState } from 'react';

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

function formatLocal(iso, timezone) {
    if (!iso) {
        return '—';
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

function recurrenceLabel(value) {
    return (
        {
            daily: 'каждый день',
            weekdays: 'по будням',
            weekly: 'еженедельно',
            monthly: 'ежемесячно',
        }[value] || value
    );
}

function statusLabel(reminder) {
    if (reminder.is_occurrence) {
        if (reminder.status === 'completed') {
            return 'Прошлое срабатывание · выполнено';
        }

        if (reminder.status === 'delivered') {
            return 'Прошлое срабатывание · доставлено';
        }

        if (reminder.status === 'failed') {
            return 'Прошлое срабатывание · ошибка доставки';
        }
    }

    if (reminder.status === 'completed') {
        return 'Выполнено';
    }

    if (reminder.status === 'delivered') {
        return 'Доставлено';
    }

    if (reminder.status === 'cancelled') {
        return 'Отменено';
    }

    if (reminder.status === 'failed') {
        return 'Ошибка доставки';
    }

    if (reminder.is_due) {
        return 'Срок наступил';
    }

    if (reminder.status === 'processing') {
        return 'Отправляется';
    }

    return 'Запланировано';
}

function deliveryIcons(reminder) {
    const parts = [];

    if (reminder.telegram_connected || reminder.delivery_channel === 'telegram' || reminder.delivery_channel === 'both') {
        parts.push('Telegram');
    }

    if (reminder.web_push_available || reminder.delivery_channel === 'web_push' || reminder.delivery_channel === 'both') {
        parts.push('Web Push');
    }

    if (reminder.delivery_state === 'no_channel' || (reminder.is_due && reminder.delivery_available === false)) {
        return 'Нет канала доставки';
    }

    if (reminder.delivery_state === 'partial') {
        return `${parts.join(' + ') || 'Доставка'} · частично`;
    }

    return parts.length ? parts.join(' + ') : null;
}

function applyPanel(payload, setters) {
    setters.setToday(payload.today ?? []);
    setters.setUpcoming(payload.upcoming ?? []);
    setters.setDue(payload.due ?? []);
    setters.setHistory(payload.history ?? []);
    setters.setTelegramConnected(Boolean(payload.telegram_connected));
    setters.setWebPushConfigured(Boolean(payload.web_push_configured));
    setters.setVapidPublicKey(payload.vapid_public_key || '');
    setters.onCountChange?.(
        typeof payload.active_count === 'number'
            ? payload.active_count
            : (payload.active?.length ?? 0),
    );
}

function ReminderActions({ reminder, busy, onSnooze, onDone, onCancel, onEdit, onCustomSnooze }) {
    if (reminder.is_occurrence) {
        return null;
    }

    return (
        <div className="mt-2 flex flex-wrap gap-2">
            {reminder.editable ? (
                <button type="button" disabled={busy} onClick={() => onEdit(reminder)} className="text-xs text-sky-300 hover:text-sky-200 disabled:opacity-50">
                    <span className="inline-flex items-center gap-1">
                        <Pencil className="h-3 w-3" />
                        Изменить
                    </span>
                </button>
            ) : null}
            {reminder.snoozable ? (
                <>
                    <button type="button" disabled={busy} onClick={() => onSnooze(reminder.id, '10m')} className="text-xs text-amber-200 hover:text-amber-100 disabled:opacity-50">
                        +10 мин
                    </button>
                    <button type="button" disabled={busy} onClick={() => onSnooze(reminder.id, '1h')} className="text-xs text-amber-200 hover:text-amber-100 disabled:opacity-50">
                        +1 час
                    </button>
                    <button type="button" disabled={busy} onClick={() => onSnooze(reminder.id, 'tomorrow')} className="text-xs text-amber-200 hover:text-amber-100 disabled:opacity-50">
                        Завтра
                    </button>
                    <button type="button" disabled={busy} onClick={() => onCustomSnooze(reminder.id)} className="text-xs text-amber-200 hover:text-amber-100 disabled:opacity-50">
                        Своё время
                    </button>
                </>
            ) : null}
            {reminder.completable ? (
                <button type="button" disabled={busy} onClick={() => onDone(reminder.id)} className="text-xs text-emerald-300 hover:text-emerald-200 disabled:opacity-50">
                    <span className="inline-flex items-center gap-1">
                        <Check className="h-3 w-3" />
                        Готово
                    </span>
                </button>
            ) : null}
            {reminder.cancellable ? (
                <button type="button" disabled={busy} onClick={() => onCancel(reminder.id)} className="text-xs text-rose-300 hover:text-rose-200 disabled:opacity-50">
                    <span className="inline-flex items-center gap-1">
                        <X className="h-3 w-3" />
                        Отменить
                    </span>
                </button>
            ) : null}
        </div>
    );
}

function ReminderList({ items, surface, busyId, onSnooze, onDone, onCancel, onEdit, onCustomSnooze }) {
    if (!items.length) {
        return <p className="text-sm text-slate-500">Пока пусто.</p>;
    }

    return (
        <ul className="space-y-2">
            {items.map((reminder) => {
                const note = deliveryIcons(reminder);
                const source = reminder.source_conversation;

                return (
                    <li key={reminder.id} className="rounded-xl border border-white/10 bg-black/20 px-3 py-2">
                        <p className="text-sm text-slate-100">{reminder.text}</p>
                        <p className="mt-1 text-[11px] text-slate-500">
                            {formatLocal(reminder.run_at_local || reminder.run_at, reminder.timezone)}
                            {' · '}
                            {reminder.timezone}
                            {' · '}
                            {statusLabel(reminder)}
                            {note ? ` · ${note}` : ''}
                            {reminder.recurrence ? ` · ${recurrenceLabel(reminder.recurrence)}` : ''}
                        </p>
                        {source ? (
                            <p className="mt-1 text-[11px] text-slate-500">
                                Из разговора:{' '}
                                <Link href={workspaceRoute(surface, 'chats.show', source.id)} className="text-sky-300 hover:text-sky-200">
                                    {source.title}
                                </Link>
                            </p>
                        ) : null}
                        {reminder.task ? (
                            <p className="mt-1 text-[11px] text-slate-500">
                                Связано с задачей: {reminder.task.title}
                            </p>
                        ) : null}
                        <ReminderActions
                            reminder={reminder}
                            busy={busyId === reminder.id}
                            onSnooze={onSnooze}
                            onDone={onDone}
                            onCancel={onCancel}
                            onEdit={onEdit}
                            onCustomSnooze={onCustomSnooze}
                        />
                    </li>
                );
            })}
        </ul>
    );
}

export default function RemindersPanel({
    open,
    surface,
    timezone,
    telegramHint,
    refreshToken = 0,
    onClose,
    onCreateInChat,
    onCountChange,
    onDataChange,
}) {
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const [today, setToday] = useState([]);
    const [upcoming, setUpcoming] = useState([]);
    const [due, setDue] = useState([]);
    const [history, setHistory] = useState([]);
    const [telegramConnected, setTelegramConnected] = useState(true);
    const [webPushConfigured, setWebPushConfigured] = useState(false);
    const [vapidPublicKey, setVapidPublicKey] = useState('');
    const [pushState, setPushState] = useState('disabled');
    const [pushBusy, setPushBusy] = useState(false);
    const [busyId, setBusyId] = useState(null);
    const [editing, setEditing] = useState(null);
    const [editText, setEditText] = useState('');
    const [editWhen, setEditWhen] = useState('');
    const [editRecurrence, setEditRecurrence] = useState('');
    const [customSnoozeId, setCustomSnoozeId] = useState(null);
    const [customWhen, setCustomWhen] = useState('');

    const setters = { setToday, setUpcoming, setDue, setHistory, setTelegramConnected, setWebPushConfigured, setVapidPublicKey, onCountChange };

    useEffect(() => {
        if (!open) {
            return undefined;
        }

        let cancelled = false;
        setLoading(true);
        setError('');

        fetch(workspaceRoute(surface, 'reminders.index'), {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        })
            .then(async (response) => {
                const payload = await response.json().catch(() => ({}));
                if (!response.ok) {
                    throw new Error(payload.message || 'Не удалось загрузить напоминания.');
                }
                return payload;
            })
            .then((payload) => {
                if (cancelled) {
                    return;
                }
                applyPanel(payload, setters);
            })
            .catch((caught) => {
                if (!cancelled) {
                    setError(caught.message || 'Не удалось загрузить напоминания.');
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setLoading(false);
                }
            });

        currentPushState()
            .then((state) => {
                if (!cancelled) {
                    setPushState(state);
                }
            })
            .catch(() => {
                if (!cancelled) {
                    setPushState(pushSupported() ? 'disabled' : 'unsupported');
                }
            });

        return () => {
            cancelled = true;
        };
    }, [open, surface, onCountChange, refreshToken]);

    const mutate = async (url, options, failure) => {
        setError('');
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

    const snoozeReminder = async (id, preset, runAtLocal) => {
        setBusyId(id);
        try {
            await mutate(
                workspaceRoute(surface, 'reminders.snooze', id),
                { method: 'POST', body: JSON.stringify({ preset, run_at_local: runAtLocal || null }) },
                'Не удалось отложить напоминание.',
            );
            setCustomSnoozeId(null);
        } catch (caught) {
            setError(caught.message || 'Не удалось отложить напоминание.');
        } finally {
            setBusyId(null);
        }
    };

    const completeReminder = async (id) => {
        setBusyId(id);
        try {
            await mutate(workspaceRoute(surface, 'reminders.complete', id), { method: 'POST' }, 'Не удалось отметить выполненным.');
        } catch (caught) {
            setError(caught.message || 'Не удалось отметить выполненным.');
        } finally {
            setBusyId(null);
        }
    };

    const cancelReminder = async (id) => {
        setBusyId(id);
        try {
            await mutate(workspaceRoute(surface, 'reminders.cancel', id), { method: 'POST' }, 'Не удалось отменить напоминание.');
        } catch (caught) {
            setError(caught.message || 'Не удалось отменить напоминание.');
        } finally {
            setBusyId(null);
        }
    };

    const saveEdit = async () => {
        if (!editing) {
            return;
        }

        setBusyId(editing.id);
        try {
            await mutate(
                workspaceRoute(surface, 'reminders.update', editing.id),
                {
                    method: 'PATCH',
                    body: JSON.stringify({
                        text: editText,
                        run_at_local: editWhen,
                        timezone: editing.timezone || timezone,
                        recurrence: editRecurrence || null,
                    }),
                },
                'Не удалось сохранить напоминание.',
            );
            setEditing(null);
        } catch (caught) {
            setError(caught.message || 'Не удалось сохранить напоминание.');
        } finally {
            setBusyId(null);
        }
    };

    const enablePush = async () => {
        if (notificationPermission() === 'denied') {
            setPushState('denied');
            return;
        }

        setPushBusy(true);
        setError('');

        try {
            const result = await enableReminderPush({
                vapidPublicKey,
                subscribeUrl: workspaceRoute(surface, 'reminders.push.store'),
                csrfToken: csrfToken(),
            });
            setPushState(result.state);
        } catch (caught) {
            setError(caught.message || 'Не удалось включить уведомления.');
        } finally {
            setPushBusy(false);
        }
    };

    const startEdit = (item) => {
        setEditing(item);
        setEditText(item.text);
        setEditWhen((item.run_at_local || '').slice(0, 19));
        setEditRecurrence(item.recurrence || '');
    };

    const listProps = {
        surface,
        busyId,
        onSnooze: snoozeReminder,
        onDone: completeReminder,
        onCancel: cancelReminder,
        onEdit: startEdit,
        onCustomSnooze: (id) => setCustomSnoozeId(id),
    };

    if (!open) {
        return null;
    }

    const pushCopy = {
        enabled: 'Уведомления включены',
        disabled: 'Уведомления выключены',
        denied: 'Браузер запретил уведомления. Разрешите их в настройках сайта.',
        unsupported: 'Этот браузер не поддерживает Web Push.',
    }[pushState];

    return (
        <div className="fixed inset-0 z-50 flex items-start justify-end bg-black/50 p-3 sm:p-6" onClick={onClose}>
            <div
                className="flex h-full max-h-[92vh] w-full max-w-md flex-col overflow-hidden rounded-2xl border border-white/10 bg-[#101826] shadow-2xl"
                onClick={(event) => event.stopPropagation()}
            >
                <div className="flex items-center justify-between border-b border-white/10 px-4 py-3">
                    <div className="flex items-center gap-2 text-white">
                        <Bell className="h-4 w-4" />
                        <h2 className="text-sm font-semibold">Напоминания</h2>
                    </div>
                    <button type="button" onClick={onClose} className="text-sm text-slate-400 hover:text-white">
                        Закрыть
                    </button>
                </div>
                <div className="min-h-0 flex-1 space-y-5 overflow-y-auto px-4 py-4">
                    <section className="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-xs text-slate-300">
                        <p>{pushCopy}</p>
                        {pushState === 'disabled' && webPushConfigured ? (
                            <button
                                type="button"
                                disabled={pushBusy}
                                onClick={enablePush}
                                className="mt-2 rounded-lg bg-sky-500/90 px-3 py-1.5 text-xs font-medium text-white hover:bg-sky-400 disabled:opacity-50"
                            >
                                {pushBusy ? 'Включаем…' : 'Включить уведомления'}
                            </button>
                        ) : null}
                        {pushState === 'denied' ? (
                            <p className="mt-1 text-[11px] text-slate-500">Повторный запрос не показывается, пока разрешение запрещено в браузере.</p>
                        ) : null}
                    </section>
                    {telegramConnected ? null : (
                        <p className="rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-xs text-slate-300">
                            {telegramHint ||
                                'Telegram не подключён. Напоминание сохранено в Jarvis. Подключите Telegram, если нужна доставка ещё и туда.'}
                        </p>
                    )}
                    <button
                        type="button"
                        onClick={onCreateInChat}
                        className="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-sky-500/90 px-3 py-2 text-sm font-medium text-white hover:bg-sky-400"
                    >
                        <Plus className="h-4 w-4" />
                        Создать в чате
                    </button>
                    {error ? <p className="text-sm text-rose-300">{error}</p> : null}
                    {editing ? (
                        <section className="space-y-2 rounded-xl border border-white/10 bg-black/20 p-3">
                            <h3 className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Изменить</h3>
                            <input
                                value={editText}
                                onChange={(event) => setEditText(event.target.value)}
                                className="w-full rounded-lg border border-white/10 bg-black/30 px-3 py-2 text-sm text-white"
                            />
                            <input
                                type="datetime-local"
                                value={editWhen.slice(0, 16)}
                                onChange={(event) => setEditWhen(event.target.value)}
                                className="w-full rounded-lg border border-white/10 bg-black/30 px-3 py-2 text-sm text-white"
                            />
                            <select
                                value={editRecurrence}
                                onChange={(event) => setEditRecurrence(event.target.value)}
                                className="w-full rounded-lg border border-white/10 bg-black/30 px-3 py-2 text-sm text-white"
                            >
                                <option value="">Без повтора</option>
                                <option value="daily">Каждый день</option>
                                <option value="weekdays">По будням</option>
                                <option value="weekly">Еженедельно</option>
                                <option value="monthly">Ежемесячно</option>
                            </select>
                            <div className="flex gap-2">
                                <button type="button" onClick={saveEdit} className="rounded-lg bg-sky-500 px-3 py-1.5 text-xs text-white">
                                    Сохранить
                                </button>
                                <button type="button" onClick={() => setEditing(null)} className="rounded-lg border border-white/10 px-3 py-1.5 text-xs text-slate-300">
                                    Закрыть
                                </button>
                            </div>
                        </section>
                    ) : null}
                    {customSnoozeId ? (
                        <section className="space-y-2 rounded-xl border border-white/10 bg-black/20 p-3">
                            <h3 className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Своё время</h3>
                            <input
                                type="datetime-local"
                                value={customWhen}
                                onChange={(event) => setCustomWhen(event.target.value)}
                                className="w-full rounded-lg border border-white/10 bg-black/30 px-3 py-2 text-sm text-white"
                            />
                            <button
                                type="button"
                                onClick={() => snoozeReminder(customSnoozeId, 'custom', customWhen)}
                                className="rounded-lg bg-amber-500/90 px-3 py-1.5 text-xs text-white"
                            >
                                Отложить
                            </button>
                        </section>
                    ) : null}
                    {loading ? (
                        <div className="flex items-center gap-2 text-sm text-slate-400">
                            <Loader2 className="h-4 w-4 animate-spin" />
                            Загрузка…
                        </div>
                    ) : (
                        <>
                            <section>
                                <h3 className="mb-2 text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Due</h3>
                                <ReminderList items={due} {...listProps} />
                            </section>
                            <section>
                                <h3 className="mb-2 text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Сегодня</h3>
                                <ReminderList items={today} {...listProps} />
                            </section>
                            <section>
                                <h3 className="mb-2 text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Предстоящие</h3>
                                <ReminderList items={upcoming} {...listProps} />
                            </section>
                            <section>
                                <h3 className="mb-2 text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">История</h3>
                                <ReminderList items={history} {...listProps} />
                            </section>
                            <p className="text-[11px] text-slate-600">
                                Время показано в {timezone || 'локальном часовом поясе'}. Готово — вы закрыли напоминание. Отменить — остановить его.
                            </p>
                        </>
                    )}
                </div>
            </div>
        </div>
    );
}
