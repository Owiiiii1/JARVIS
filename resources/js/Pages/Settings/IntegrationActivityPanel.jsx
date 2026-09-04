import { usePage } from '@inertiajs/react';

export default function IntegrationActivityPanel() {
    const { integrations = {}, locale = 'en' } = usePage().props;
    const executions = integrations.recent_executions ?? [];

    const text = {
        en: {
            title: 'Recent tool executions',
            hint: 'Safe audit trail only: time, tool, provider, status, duration, error code. No arguments, tokens, or result bodies.',
            empty: 'No tool executions yet.',
            time: 'Time',
            tool: 'Tool',
            provider: 'Provider',
            status: 'Status',
            duration: 'Duration',
            error: 'Error',
        },
        ru: {
            title: 'Recent tool executions',
            hint: 'Только безопасный аудит: time, tool, provider, status, duration, error code. Без arguments, tokens и тел результатов.',
            empty: 'Пока нет выполнений tools.',
            time: 'Time',
            tool: 'Tool',
            provider: 'Provider',
            status: 'Status',
            duration: 'Duration',
            error: 'Error',
        },
        uk: {
            title: 'Recent tool executions',
            hint: 'Лише безпечний аудит: time, tool, provider, status, duration, error code. Без arguments, tokens і тіл результатів.',
            empty: 'Поки немає виконань tools.',
            time: 'Time',
            tool: 'Tool',
            provider: 'Provider',
            status: 'Status',
            duration: 'Duration',
            error: 'Error',
        },
    };
    const t = text[locale] ?? text.en;

    return (
        <section className="rounded-xl border border-[#E6DCC8] bg-white p-4">
            <h2 className="text-base font-semibold text-slate-900">{t.title}</h2>
            <p className="mt-1 text-sm text-slate-600">{t.hint}</p>
            {executions.length === 0 ? (
                <p className="mt-3 text-sm text-slate-600">{t.empty}</p>
            ) : (
                <div className="mt-3 overflow-x-auto">
                    <table className="min-w-full text-left text-sm">
                        <thead className="text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th className="py-2 pr-4">{t.time}</th>
                                <th className="py-2 pr-4">{t.tool}</th>
                                <th className="py-2 pr-4">{t.provider}</th>
                                <th className="py-2 pr-4">{t.status}</th>
                                <th className="py-2 pr-4">{t.duration}</th>
                                <th className="py-2">{t.error}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {executions.map((row) => (
                                <tr key={row.id} className="border-t border-slate-100">
                                    <td className="py-2 pr-4 text-slate-600">{row.time ?? '—'}</td>
                                    <td className="py-2 pr-4 font-medium text-slate-800">{row.tool}</td>
                                    <td className="py-2 pr-4 text-slate-600">{row.provider ?? 'core'}</td>
                                    <td className="py-2 pr-4 text-slate-700">{row.status}</td>
                                    <td className="py-2 pr-4 text-slate-600">
                                        {row.duration_ms != null ? `${row.duration_ms} ms` : '—'}
                                    </td>
                                    <td className="py-2 text-slate-600">{row.error_code ?? '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </section>
    );
}
