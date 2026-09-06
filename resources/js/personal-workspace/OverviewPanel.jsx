import PanelSection from '@/personal-workspace/components/PanelSection';
import PanelShell from '@/personal-workspace/components/PanelShell';
import { workspaceRoute } from '@/personal-workspace/named';
import { LayoutDashboard } from 'lucide-react';
import { useEffect, useState } from 'react';

/**
 * Every string here already arrives humanised from the synthesis layer, so the panel only
 * decides layout — it must never rebuild meaning out of raw types.
 */
function ItemList({ title, items, empty }) {
    const rows = items || [];

    return (
        <PanelSection title={title} count={rows.length} empty={empty}>
            {rows.map((item, index) => (
                <li key={item.id || item.title || index} className="rounded-xl border border-white/10 bg-black/20 px-3 py-2">
                    <p className="text-sm text-slate-100">{item.title}</p>
                    {item.why ? <p className="mt-0.5 text-xs text-slate-400">{item.why}</p> : null}
                    {item.recommended_next_step ? (
                        <p className="mt-1 text-[11px] text-slate-500">{item.recommended_next_step}</p>
                    ) : null}
                </li>
            ))}
        </PanelSection>
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
        setData(null);

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
        <PanelShell icon={LayoutDashboard} title="Обзор" onClose={onClose} loading={loading} error={error}>
            {error ? null : (
                <>
                    <ItemList title="Сегодня и ближайшее" items={data?.upcoming} empty="На ближайшее время ничего не запланировано." />
                    <ItemList title="Нужно внимание" items={data?.attention} empty="Сейчас ничего срочного." />
                    <ItemList title="Жду" items={data?.waiting_for} empty="Нет открытых ожиданий." />
                    <ItemList title="Что изменилось" items={data?.recent_changes} empty="Нет недавних изменений." />
                    <ItemList title="Открытая работа" items={data?.open_work} empty="Нет открытых задач в этом срезе." />
                </>
            )}
        </PanelShell>
    );
}
