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

export default function IntegrationProviderCard({ provider, t, disconnecting, onDisconnect }) {
    return (
        <section className="rounded-xl border border-[#E6DCC8] bg-[#FBF8F1] p-4">
            <div className="flex items-start justify-between gap-3">
                <h2 className="text-base font-semibold text-slate-900">{provider.display_name}</h2>
                <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${statusClass(provider.state)}`}>
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
            {provider.provider === 'google' && (
                <div className="mt-4 flex flex-wrap gap-2">
                    {actionAvailable(provider, 'connect') && (
                        <a
                            href={route('integrations.google.connect')}
                            className="inline-flex h-9 items-center rounded-lg bg-indigo-600 px-3 text-sm font-semibold text-white hover:bg-indigo-700"
                        >
                            {t.connect}
                        </a>
                    )}
                    {actionAvailable(provider, 'reconnect') && (
                        <a
                            href={route('integrations.google.connect')}
                            className="inline-flex h-9 items-center rounded-lg bg-indigo-600 px-3 text-sm font-semibold text-white hover:bg-indigo-700"
                        >
                            {t.reconnect}
                        </a>
                    )}
                    {actionAvailable(provider, 'enable_calendar') && (
                        <a
                            href={route('integrations.google.connect', { intent: 'calendar' })}
                            className="inline-flex h-9 items-center rounded-lg bg-emerald-600 px-3 text-sm font-semibold text-white hover:bg-emerald-700"
                        >
                            {t.enableCalendar}
                        </a>
                    )}
                    {actionAvailable(provider, 'enable_gmail') && (
                        <a
                            href={route('integrations.google.connect', { intent: 'gmail' })}
                            className="inline-flex h-9 items-center rounded-lg bg-emerald-600 px-3 text-sm font-semibold text-white hover:bg-emerald-700"
                        >
                            {t.enableGmail}
                        </a>
                    )}
                    {!provider.configured && (
                        <button
                            type="button"
                            disabled
                            className="inline-flex h-9 items-center rounded-lg bg-slate-200 px-3 text-sm font-medium text-slate-500"
                        >
                            {t.connect}
                        </button>
                    )}
                    {actionAvailable(provider, 'disconnect') && (
                        <button
                            type="button"
                            onClick={() => onDisconnect('google')}
                            disabled={disconnecting !== null}
                            className="inline-flex h-9 items-center rounded-lg border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-60"
                        >
                            {t.disconnect}
                        </button>
                    )}
                </div>
            )}
            {provider.provider === 'github' && (
                <div className="mt-4 flex flex-wrap gap-2">
                    {actionAvailable(provider, 'connect') && (
                        <a
                            href={route('integrations.github.connect')}
                            className="inline-flex h-9 items-center rounded-lg bg-indigo-600 px-3 text-sm font-semibold text-white hover:bg-indigo-700"
                        >
                            {t.connectGitHub}
                        </a>
                    )}
                    {actionAvailable(provider, 'reconnect') && (
                        <a
                            href={route('integrations.github.connect')}
                            className="inline-flex h-9 items-center rounded-lg bg-indigo-600 px-3 text-sm font-semibold text-white hover:bg-indigo-700"
                        >
                            {t.reconnect}
                        </a>
                    )}
                    {!provider.configured && (
                        <button
                            type="button"
                            disabled
                            className="inline-flex h-9 items-center rounded-lg bg-slate-200 px-3 text-sm font-medium text-slate-500"
                        >
                            {t.connectGitHub}
                        </button>
                    )}
                    {actionAvailable(provider, 'disconnect') && (
                        <button
                            type="button"
                            onClick={() => onDisconnect('github')}
                            disabled={disconnecting !== null}
                            className="inline-flex h-9 items-center rounded-lg border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-60"
                        >
                            {t.disconnect}
                        </button>
                    )}
                </div>
            )}
        </section>
    );
}
