import { Link, usePage } from '@inertiajs/react';

function statusClass(state) {
    if (state === 'connected') {
        return 'bg-emerald-100 text-emerald-700';
    }
    if (state === 'error' || state === 'revoked') {
        return 'bg-red-100 text-red-700';
    }
    if (state === 'connecting') {
        return 'bg-amber-100 text-amber-800';
    }

    return 'bg-slate-100 text-slate-700';
}

export default function IntegrationsPanel() {
    const { integrations = {}, locale = 'en' } = usePage().props;
    const providers = integrations.providers ?? [];
    const executions = integrations.recent_executions ?? [];

    const text = {
        en: {
            hint: 'Owner integration cards. Telegram uses the existing bot settings. Google and ElevenLabs are placeholders.',
            connectNext: 'Available next milestone',
            recent: 'Recent Tool Executions',
            empty: 'No tool executions yet.',
            time: 'Time',
            tool: 'Tool',
            provider: 'Provider',
            status: 'Status',
            duration: 'Duration',
            error: 'Error',
            telegramSettings: 'Telegram settings',
        },
        ru: {
            hint: 'Карточки интеграций owner. Telegram читает существующие настройки бота. Google и ElevenLabs — заглушки.',
            connectNext: 'Available next milestone',
            recent: 'Recent Tool Executions',
            empty: 'Пока нет выполнений tools.',
            time: 'Time',
            tool: 'Tool',
            provider: 'Provider',
            status: 'Status',
            duration: 'Duration',
            error: 'Error',
            telegramSettings: 'Telegram settings',
        },
        uk: {
            hint: 'Картки інтеграцій owner. Telegram читає наявні налаштування бота. Google і ElevenLabs — заглушки.',
            connectNext: 'Available next milestone',
            recent: 'Recent Tool Executions',
            empty: 'Поки немає виконань tools.',
            time: 'Time',
            tool: 'Tool',
            provider: 'Provider',
            status: 'Status',
            duration: 'Duration',
            error: 'Error',
            telegramSettings: 'Telegram settings',
        },
    };
    const t = text[locale] ?? text.en;

    return (
        <div className="space-y-6">
            <p className="text-sm text-slate-600">{t.hint}</p>

            <div className="grid gap-4 md:grid-cols-3">
                {providers.map((provider) => (
                    <section
                        key={provider.provider}
                        className="rounded-xl border border-[#E6DCC8] bg-[#FBF8F1] p-4"
                    >
                        <div className="flex items-start justify-between gap-3">
                            <h2 className="text-base font-semibold text-slate-900">
                                {provider.display_name}
                            </h2>
                            <span
                                className={`rounded-full px-2.5 py-1 text-xs font-semibold ${statusClass(provider.state)}`}
                            >
                                {provider.label}
                            </span>
                        </div>
                        {provider.account_label && (
                            <p className="mt-2 text-sm text-slate-700">{provider.account_label}</p>
                        )}
                        {provider.diagnostic_message && (
                            <p className="mt-2 text-sm text-slate-600">{provider.diagnostic_message}</p>
                        )}
                        <div className="mt-4 flex flex-wrap gap-2">
                            {provider.provider === 'google' && (
                                <button
                                    type="button"
                                    disabled
                                    className="inline-flex h-9 items-center rounded-lg bg-slate-200 px-3 text-sm font-medium text-slate-500"
                                >
                                    {t.connectNext}
                                </button>
                            )}
                            {provider.provider === 'telegram' && (
                                <Link
                                    href={route('settings.index', { tab: 'telegram' })}
                                    className="inline-flex h-9 items-center rounded-lg bg-indigo-600 px-3 text-sm font-semibold text-white hover:bg-indigo-700"
                                >
                                    {t.telegramSettings}
                                </Link>
                            )}
                        </div>
                    </section>
                ))}
            </div>

            <section className="rounded-xl border border-[#E6DCC8] bg-white p-4">
                <h2 className="text-base font-semibold text-slate-900">{t.recent}</h2>
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
        </div>
    );
}
