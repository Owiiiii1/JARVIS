import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import TelegramPanel from './TelegramPanel';
import WebResearchPanel from './WebResearchPanel';

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

function actionAvailable(provider, key) {
    return (provider.actions ?? []).some((action) => action.key === key && action.available);
}

export default function IntegrationsPanel() {
    const { integrations = {}, locale = 'en', flash = {} } = usePage().props;
    const providers = integrations.providers ?? [];
    const executions = integrations.recent_executions ?? [];
    const [disconnecting, setDisconnecting] = useState(null);

    const text = {
        en: {
            hint: 'Owner integration cards. Web Research, Telegram bot token, Google, and GitHub are configured here. Workspace shows read-only web search status only.',
            recent: 'Recent Tool Executions',
            empty: 'No tool executions yet.',
            time: 'Time',
            tool: 'Tool',
            provider: 'Provider',
            status: 'Status',
            duration: 'Duration',
            error: 'Error',
            connect: 'Connect Google',
            connectGitHub: 'Connect GitHub',
            reconnect: 'Reconnect',
            disconnect: 'Disconnect',
            connectedAt: 'Connected',
            scopes: 'Scopes',
            tokenHealth: 'Token health',
            enableCalendar: 'Enable Calendar',
            enableGmail: 'Enable Gmail',
            capabilities: 'Capabilities',
        },
        ru: {
            hint: 'Карточки интеграций owner. Web Research, токен Telegram, Google и GitHub настраиваются здесь. Workspace показывает только read-only статус web search.',
            recent: 'Recent Tool Executions',
            empty: 'Пока нет выполнений tools.',
            time: 'Time',
            tool: 'Tool',
            provider: 'Provider',
            status: 'Status',
            duration: 'Duration',
            error: 'Error',
            connect: 'Connect Google',
            connectGitHub: 'Connect GitHub',
            reconnect: 'Reconnect',
            disconnect: 'Disconnect',
            connectedAt: 'Connected',
            scopes: 'Scopes',
            tokenHealth: 'Token health',
            enableCalendar: 'Enable Calendar',
            enableGmail: 'Enable Gmail',
            capabilities: 'Capabilities',
        },
        uk: {
            hint: 'Картки інтеграцій owner. Web Research, токен Telegram, Google і GitHub налаштовуються тут. Workspace показує лише read-only статус web search.',
            recent: 'Recent Tool Executions',
            empty: 'Поки немає виконань tools.',
            time: 'Time',
            tool: 'Tool',
            provider: 'Provider',
            status: 'Status',
            duration: 'Duration',
            error: 'Error',
            connect: 'Connect Google',
            connectGitHub: 'Connect GitHub',
            reconnect: 'Reconnect',
            disconnect: 'Disconnect',
            connectedAt: 'Connected',
            scopes: 'Scopes',
            tokenHealth: 'Token health',
            enableCalendar: 'Enable Calendar',
            enableGmail: 'Enable Gmail',
            capabilities: 'Capabilities',
        },
    };
    const t = text[locale] ?? text.en;

    const disconnectProvider = (provider) => {
        if (disconnecting) {
            return;
        }

        const namedRoute = provider === 'github'
            ? 'integrations.github.disconnect'
            : 'integrations.google.disconnect';

        setDisconnecting(provider);
        router.post(route(namedRoute), {}, {
            preserveScroll: true,
            onFinish: () => setDisconnecting(null),
        });
    };

    return (
        <div className="space-y-6">
            <p className="text-sm text-slate-600">{t.hint}</p>

            {flash.success && (
                <p className="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{flash.success}</p>
            )}
            {flash.warning && (
                <p className="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">{flash.warning}</p>
            )}
            {flash.error && (
                <p className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{flash.error}</p>
            )}

            <WebResearchPanel />

            <div className="grid gap-4 md:grid-cols-3">
                {providers.map((provider) => (
                    <section
                        key={provider.provider}
                        className={`rounded-xl border border-[#E6DCC8] bg-[#FBF8F1] p-4 ${
                            provider.provider === 'telegram' ? 'md:col-span-3 md:order-last' : ''
                        }`}
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
                        {provider.capability_states?.length > 0 && (
                            <ul className="mt-2 space-y-1 text-sm text-slate-600">
                                {provider.capability_states.map((item) => (
                                    <li key={item.key}>
                                        {item.label}: {item.state.replaceAll('_', ' ')}
                                    </li>
                                ))}
                            </ul>
                        )}
                        {provider.scope_labels?.length > 0 && (
                            <p className="mt-1 text-sm text-slate-600">
                                {t.scopes}: {provider.scope_labels.join(', ')}
                            </p>
                        )}
                        {provider.connected_at && (
                            <p className="mt-1 text-sm text-slate-600">
                                {t.connectedAt}: {provider.connected_at}
                            </p>
                        )}
                        {provider.token_health && (
                            <p className="mt-1 text-sm text-slate-600">
                                {t.tokenHealth}: {provider.token_health}
                            </p>
                        )}
                        {provider.diagnostic_message && (
                            <p className="mt-2 text-sm text-slate-600">{provider.diagnostic_message}</p>
                        )}
                        {provider.provider !== 'telegram' && (
                        <div className="mt-4 flex flex-wrap gap-2">
                            {provider.provider === 'google' && actionAvailable(provider, 'connect') && (
                                <a
                                    href={route('integrations.google.connect')}
                                    className="inline-flex h-9 items-center rounded-lg bg-indigo-600 px-3 text-sm font-semibold text-white hover:bg-indigo-700"
                                >
                                    {t.connect}
                                </a>
                            )}
                            {provider.provider === 'google' && actionAvailable(provider, 'reconnect') && (
                                <a
                                    href={route('integrations.google.connect')}
                                    className="inline-flex h-9 items-center rounded-lg bg-indigo-600 px-3 text-sm font-semibold text-white hover:bg-indigo-700"
                                >
                                    {t.reconnect}
                                </a>
                            )}
                            {provider.provider === 'google' && actionAvailable(provider, 'enable_calendar') && (
                                <a
                                    href={route('integrations.google.connect', { intent: 'calendar' })}
                                    className="inline-flex h-9 items-center rounded-lg bg-emerald-600 px-3 text-sm font-semibold text-white hover:bg-emerald-700"
                                >
                                    {t.enableCalendar}
                                </a>
                            )}
                            {provider.provider === 'google' && actionAvailable(provider, 'enable_gmail') && (
                                <a
                                    href={route('integrations.google.connect', { intent: 'gmail' })}
                                    className="inline-flex h-9 items-center rounded-lg bg-emerald-600 px-3 text-sm font-semibold text-white hover:bg-emerald-700"
                                >
                                    {t.enableGmail}
                                </a>
                            )}
                            {provider.provider === 'google' && !provider.configured && (
                                <button
                                    type="button"
                                    disabled
                                    className="inline-flex h-9 items-center rounded-lg bg-slate-200 px-3 text-sm font-medium text-slate-500"
                                >
                                    {t.connect}
                                </button>
                            )}
                            {provider.provider === 'google' && actionAvailable(provider, 'disconnect') && (
                                <button
                                    type="button"
                                    onClick={() => disconnectProvider('google')}
                                    disabled={disconnecting !== null}
                                    className="inline-flex h-9 items-center rounded-lg border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-60"
                                >
                                    {t.disconnect}
                                </button>
                            )}
                            {provider.provider === 'github' && actionAvailable(provider, 'connect') && (
                                <a
                                    href={route('integrations.github.connect')}
                                    className="inline-flex h-9 items-center rounded-lg bg-indigo-600 px-3 text-sm font-semibold text-white hover:bg-indigo-700"
                                >
                                    {t.connectGitHub}
                                </a>
                            )}
                            {provider.provider === 'github' && actionAvailable(provider, 'reconnect') && (
                                <a
                                    href={route('integrations.github.connect')}
                                    className="inline-flex h-9 items-center rounded-lg bg-indigo-600 px-3 text-sm font-semibold text-white hover:bg-indigo-700"
                                >
                                    {t.reconnect}
                                </a>
                            )}
                            {provider.provider === 'github' && !provider.configured && (
                                <button
                                    type="button"
                                    disabled
                                    className="inline-flex h-9 items-center rounded-lg bg-slate-200 px-3 text-sm font-medium text-slate-500"
                                >
                                    {t.connectGitHub}
                                </button>
                            )}
                            {provider.provider === 'github' && actionAvailable(provider, 'disconnect') && (
                                <button
                                    type="button"
                                    onClick={() => disconnectProvider('github')}
                                    disabled={disconnecting !== null}
                                    className="inline-flex h-9 items-center rounded-lg border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-60"
                                >
                                    {t.disconnect}
                                </button>
                            )}
                        </div>
                        )}
                        {provider.provider === 'telegram' && (
                            <div className="mt-4">
                                <TelegramPanel />
                            </div>
                        )}
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
