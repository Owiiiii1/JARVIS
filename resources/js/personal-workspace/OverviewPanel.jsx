import { workspaceRoute } from '@/personal-workspace/named';
import { LayoutDashboard, Loader2 } from 'lucide-react';
import { useEffect, useState } from 'react';

function ItemList({ title, items, empty }) {
    const rows = items || [];

    return (
        <section className="mb-4">
            <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">{title}</h3>
            {rows.length === 0 ? (
                <p className="text-sm text-slate-500">{empty}</p>
            ) : (
                <ul className="space-y-2">
                    {rows.map((item, index) => (
                        <li key={item.fingerprint || item.title || index} className="rounded-xl bg-black/20 px-3 py-2">
                            <div className="text-sm text-white">{item.title}</div>
                            {item.why ? <div className="mt-0.5 text-[11px] text-slate-500">{item.why}</div> : null}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

export default function OverviewPanel({ open, surface, refreshToken = 0, onClose }) {
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const [data, setData] = useState(null);

    useEffect(() => {
        if (!open) {
            return undefined;
        }

        const controller = new AbortController();
        setLoading(true);
        setError('');

        fetch(workspaceRoute(surface, 'synthesis.index') + '?type=attention_needed', {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            signal: controller.signal,
        })
            .then(async (response) => {
                const payload = await response.json().catch(() => ({}));
                if (!response.ok) {
                    throw new Error(payload.message || payload.error || 'Не удалось загрузить обзор.');
                }
                setData(payload);
            })
            .catch((caught) => {
                if (caught.name !== 'AbortError') {
                    setError(caught.message || 'Не удалось загрузить обзор.');
                }
            })
            .finally(() => setLoading(false));

        return () => controller.abort();
    }, [open, surface, refreshToken]);

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
                        <LayoutDashboard className="h-4 w-4 text-sky-300" />
                        <h2 className="text-sm font-semibold">Обзор</h2>
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
                    <ItemList title="Нужно внимание" items={data?.attention} empty="Сейчас ничего срочного." />
                    <ItemList title="Жду" items={data?.waiting_for} empty="Нет открытых ожиданий." />
                    <ItemList title="Что изменилось" items={data?.recent_changes} empty="Нет недавних изменений." />
                    <ItemList title="Открытая работа" items={data?.open_work} empty="Нет открытых задач в этом срезе." />
                </div>
            </aside>
        </div>
    );
}
