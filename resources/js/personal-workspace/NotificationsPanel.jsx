import { workspaceRoute } from '@/personal-workspace/named';
import { Link } from '@inertiajs/react';
import { Inbox, Loader2 } from 'lucide-react';
import { useEffect, useState } from 'react';

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

function formatWhen(iso) {
    if (!iso) {
        return '';
    }

    try {
        return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso));
    } catch {
        return iso;
    }
}

export default function NotificationsPanel({ open, surface, refreshToken = 0, onClose, onCountChange }) {
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const [items, setItems] = useState([]);
    const [unreadOnly, setUnreadOnly] = useState(false);
    const [busyId, setBusyId] = useState(null);

    const apply = (payload) => {
        setItems(payload.items || []);
        onCountChange?.(Number(payload.unread_count || 0));
    };

    const load = (unread = unreadOnly) => {
        setLoading(true);
        setError('');
        const url = workspaceRoute(surface, 'notifications.index') + (unread ? '?unread=1' : '');

        return fetch(url, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then(async (response) => {
                const payload = await response.json().catch(() => ({}));
                if (!response.ok) {
                    throw new Error(payload.message || 'Не удалось загрузить уведомления.');
                }
                apply(payload);
            })
            .catch((caught) => setError(caught.message))
            .finally(() => setLoading(false));
    };

    useEffect(() => {
        if (!open) {
            return undefined;
        }

        load(unreadOnly);

        return undefined;
    }, [open, surface, unreadOnly, refreshToken]);

    const mutate = async (name, id = null) => {
        setBusyId(id ?? name);
        try {
            const url = id
                ? workspaceRoute(surface, name, id)
                : workspaceRoute(surface, name);
            const response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({}),
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok) {
                throw new Error(payload.message || 'Не удалось обновить уведомление.');
            }
            apply(payload);
        } catch (caught) {
            setError(caught.message);
        } finally {
            setBusyId(null);
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
                        <Inbox className="h-4 w-4" />
                        <h2 className="text-sm font-semibold">Уведомления</h2>
                    </div>
                    <button type="button" onClick={onClose} className="text-sm text-slate-400 hover:text-white">
                        Закрыть
                    </button>
                </div>
                <div className="min-h-0 flex-1 space-y-4 overflow-y-auto px-4 py-4">
                    <div className="flex gap-2 text-xs">
                        <button type="button" onClick={() => setUnreadOnly(false)} className={!unreadOnly ? 'text-white' : 'text-slate-500'}>
                            Все
                        </button>
                        <button type="button" onClick={() => setUnreadOnly(true)} className={unreadOnly ? 'text-white' : 'text-slate-500'}>
                            Непрочитанные
                        </button>
                        <button type="button" onClick={() => mutate('notifications.read-all')} className="ml-auto text-sky-300">
                            Прочитать все
                        </button>
                    </div>
                    {error ? <p className="text-xs text-rose-300">{error}</p> : null}
                    {loading ? (
                        <p className="flex items-center gap-2 text-sm text-slate-400">
                            <Loader2 className="h-4 w-4 animate-spin" />
                            Загрузка…
                        </p>
                    ) : items.length === 0 ? (
                        <p className="text-sm text-slate-500">Пока пусто.</p>
                    ) : (
                        <ul className="space-y-2">
                            {items.map((item) => (
                                <li key={item.id} className={`rounded-xl border px-3 py-2 ${item.unread ? 'border-sky-500/30 bg-sky-500/5' : 'border-white/10 bg-black/20'}`}>
                                    <p className="text-sm text-slate-100">{item.title}</p>
                                    <p className="mt-1 whitespace-pre-wrap text-[12px] text-slate-400">{item.body}</p>
                                    <p className="mt-1 text-[11px] text-slate-500">{formatWhen(item.occurred_at)} · {item.type}</p>
                                    {item.action_url ? (
                                        <Link href={item.action_url} className="mt-1 inline-block text-[11px] text-sky-300 hover:text-sky-200">
                                            Открыть
                                        </Link>
                                    ) : null}
                                    <div className="mt-2 flex gap-3 text-[11px]">
                                        {item.unread ? (
                                            <button type="button" disabled={busyId === item.id} onClick={() => mutate('notifications.read', item.id)} className="text-sky-300">
                                                Прочитано
                                            </button>
                                        ) : null}
                                        <button type="button" disabled={busyId === item.id} onClick={() => mutate('notifications.dismiss', item.id)} className="text-slate-400">
                                            Скрыть
                                        </button>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </div>
        </div>
    );
}
