import { workspaceRoute } from '@/personal-workspace/named';
import { Bell, Loader2, Plus } from 'lucide-react';
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

function statusLabel(reminder) {
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

function deliveryNote(reminder) {
    if (reminder.delivery_state === 'no_channel' || (reminder.is_due && reminder.delivery_available === false)) {
        return 'Telegram не подключён';
    }

    if (reminder.status === 'failed') {
        return 'Ошибка доставки Telegram';
    }

    if (reminder.delivery_channel === 'telegram' || reminder.delivery_available) {
        return 'Telegram';
    }

    return null;
}

function ReminderList({ items, onCancel, cancellingId }) {
    if (!items.length) {
        return <p className="text-sm text-slate-500">Пока пусто.</p>;
    }

    return (
        <ul className="space-y-2">
            {items.map((reminder) => {
                const note = deliveryNote(reminder);

                return (
                <li key={reminder.id} className="rounded-xl border border-white/10 bg-black/20 px-3 py-2">
                    <p className="text-sm text-slate-100">{reminder.text}</p>
                    <p className="mt-1 text-[11px] text-slate-500">
                        {formatLocal(reminder.run_at_local || reminder.run_at, reminder.timezone)}
                        {' · '}
                        {statusLabel(reminder)}
                        {note ? ` · ${note}` : ''}
                        {reminder.recurrence ? ` · ${reminder.recurrence}` : ''}
                    </p>
                    {reminder.cancellable ? (
                        <button
                            type="button"
                            disabled={cancellingId === reminder.id}
                            onClick={() => onCancel(reminder.id)}
                            className="mt-2 text-xs text-rose-300 hover:text-rose-200 disabled:opacity-50"
                        >
                            {cancellingId === reminder.id ? 'Отмена…' : 'Отменить'}
                        </button>
                    ) : null}
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
    onClose,
    onCreateInChat,
    onCountChange,
}) {
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const [active, setActive] = useState([]);
    const [history, setHistory] = useState([]);
    const [telegramConnected, setTelegramConnected] = useState(true);
    const [cancellingId, setCancellingId] = useState(null);

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
                setActive(payload.active ?? []);
                setHistory(payload.history ?? []);
                setTelegramConnected(Boolean(payload.telegram_connected));
                onCountChange?.(typeof payload.active_count === 'number' ? payload.active_count : (payload.active?.length ?? 0));
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

        return () => {
            cancelled = true;
        };
    }, [open, surface, onCountChange]);

    const cancelReminder = async (id) => {
        setCancellingId(id);
        setError('');

        try {
            const response = await fetch(workspaceRoute(surface, 'reminders.cancel', id), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            const payload = await response.json().catch(() => ({}));

            if (!response.ok) {
                throw new Error(payload.message || 'Не удалось отменить напоминание.');
            }

            setActive(payload.active ?? []);
            setHistory(payload.history ?? []);
            onCountChange?.(payload.active_count ?? payload.active?.length ?? 0);
        } catch (caught) {
            setError(caught.message || 'Не удалось отменить напоминание.');
        } finally {
            setCancellingId(null);
        }
    };

    if (!open) {
        return null;
    }

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
                    {telegramConnected ? null : (
                        <p className="rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-xs text-slate-300">
                            {telegramHint ||
                                'Telegram не подключён. Напоминание сохранено в Jarvis, но Telegram-доставка недоступна. Подключите Telegram для доставки вне Web.'}
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
                    {loading ? (
                        <div className="flex items-center gap-2 text-sm text-slate-400">
                            <Loader2 className="h-4 w-4 animate-spin" />
                            Загрузка…
                        </div>
                    ) : (
                        <>
                            <section>
                                <h3 className="mb-2 text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Активные</h3>
                                <ReminderList items={active} onCancel={cancelReminder} cancellingId={cancellingId} />
                            </section>
                            <section>
                                <h3 className="mb-2 text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Прошедшие</h3>
                                <ReminderList items={history} onCancel={cancelReminder} cancellingId={cancellingId} />
                            </section>
                            <p className="text-[11px] text-slate-600">
                                Время показано в {timezone || 'локальном часовом поясе'}. Recurrence пока не поддерживается.
                            </p>
                        </>
                    )}
                </div>
            </div>
        </div>
    );
}
