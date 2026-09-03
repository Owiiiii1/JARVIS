import { router, usePage } from '@inertiajs/react';
import { CheckCircle2, KeyRound, Loader2, PlugZap, Power } from 'lucide-react';
import { useMemo, useState } from 'react';

const PROVIDERS = [
    { provider: 'openai', title: 'ChatGPT / OpenAI' },
    { provider: 'anthropic', title: 'Claude / Anthropic' },
    { provider: 'gemini', title: 'Gemini / Google' },
];

export default function AiPanel() {
    const { providers = [], locale = 'en', errors = {} } = usePage().props;
    const [processingProvider, setProcessingProvider] = useState(null);
    const [apiKeys, setApiKeys] = useState({});
    const [selectedModels, setSelectedModels] = useState({});

    const text = {
        en: {
            subtitle: 'Connect one provider and activate one model at a time.',
            apiKey: 'API key',
            saveKey: 'Save key',
            check: 'Check connection',
            activate: 'Activate',
            deactivate: 'Deactivate all',
            activeModel: 'Active model',
            modelSelect: 'Available models',
            noModels: 'No models loaded yet',
            notConnected: 'Not connected',
            connected: 'Connected',
            active: 'Active',
            error: 'Error',
            savedMask: 'Saved key',
        },
        ru: {
            subtitle: 'Connect one provider and activate one model at a time.',
            apiKey: 'API key',
            saveKey: 'Save key',
            check: 'Check connection',
            activate: 'Activate',
            deactivate: 'Deactivate all',
            activeModel: 'Active model',
            modelSelect: 'Available models',
            noModels: 'No models loaded yet',
            notConnected: 'Not connected',
            connected: 'Connected',
            active: 'Active',
            error: 'Error',
            savedMask: 'Saved key',
        },
        uk: {
            subtitle: 'Connect one provider and activate one model at a time.',
            apiKey: 'API key',
            saveKey: 'Save key',
            check: 'Check connection',
            activate: 'Activate',
            deactivate: 'Deactivate all',
            activeModel: 'Active model',
            modelSelect: 'Available models',
            noModels: 'No models loaded yet',
            notConnected: 'Not connected',
            connected: 'Connected',
            active: 'Active',
            error: 'Error',
            savedMask: 'Saved key',
        },
    };
    const t = text[locale] ?? text.en;

    const providerMap = useMemo(
        () => Object.fromEntries(providers.map((item) => [item.provider, item])),
        [providers],
    );

    const statusChip = (item) => {
        if (item?.is_active && item?.is_connected) {
            return { label: t.active, className: 'bg-emerald-100 text-emerald-700' };
        }
        if (item?.is_connected) {
            return { label: t.connected, className: 'bg-indigo-100 text-indigo-700' };
        }
        if (item?.last_error) {
            return { label: t.error, className: 'bg-red-100 text-red-700' };
        }

        return { label: t.notConnected, className: 'bg-slate-100 text-slate-700' };
    };

    const submitWithLock = (provider, callback) => {
        setProcessingProvider(provider);
        callback({
            preserveScroll: true,
            onFinish: () => setProcessingProvider(null),
        });
    };

    return (
        <div className="space-y-6">
            <div className="app-widget p-4">
                <p className="text-sm text-slate-600">{t.subtitle}</p>
                <button
                    type="button"
                    onClick={() =>
                        router.post(route('ai-settings.deactivate'), {}, { preserveScroll: true })
                    }
                    className="mt-3 inline-flex h-9 items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-3 text-sm font-medium text-red-700 transition hover:bg-red-100"
                >
                    <Power className="h-4 w-4" />
                    {t.deactivate}
                </button>
            </div>

            <div className="grid grid-cols-1 gap-4 xl:grid-cols-3">
                {PROVIDERS.map(({ provider, title }) => {
                    const item = providerMap[provider] ?? {};
                    const chip = statusChip(item);
                    const models = item.available_models ?? [];
                    const selectedModel = selectedModels[provider] ?? item.active_model ?? '';
                    const busy = processingProvider === provider;

                    return (
                        <div key={provider} className="app-widget p-4">
                            <div className="flex items-start justify-between gap-2">
                                <h2 className="text-base font-semibold text-slate-900">{title}</h2>
                                <span className={`rounded-full px-2 py-1 text-xs font-semibold ${chip.className}`}>
                                    {chip.label}
                                </span>
                            </div>

                            <div className="mt-4 space-y-3">
                                <label className="block text-sm font-medium text-slate-700">{t.apiKey}</label>
                                <input
                                    type="password"
                                    placeholder={item.has_api_key ? '••••••••' : ''}
                                    value={apiKeys[provider] ?? ''}
                                    onChange={(e) =>
                                        setApiKeys((prev) => ({ ...prev, [provider]: e.target.value }))
                                    }
                                    className="block h-10 w-full rounded-lg border border-slate-300 px-3 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-100"
                                />
                                {item.api_key_masked && (
                                    <p className="text-xs text-slate-500">
                                        {t.savedMask}: <span className="font-medium">{item.api_key_masked}</span>
                                    </p>
                                )}

                                <div className="flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        disabled={busy || !(apiKeys[provider] ?? '').trim()}
                                        onClick={() =>
                                            submitWithLock(provider, (opts) =>
                                                router.post(
                                                    route('ai-settings.save-key', provider),
                                                    {
                                                        provider,
                                                        api_key: apiKeys[provider],
                                                    },
                                                    {
                                                        ...opts,
                                                        onSuccess: () =>
                                                            setApiKeys((prev) => ({
                                                                ...prev,
                                                                [provider]: '',
                                                            })),
                                                    },
                                                ),
                                            )
                                        }
                                        className="inline-flex h-9 items-center gap-2 rounded-lg bg-indigo-600 px-3 text-sm font-medium text-white transition hover:bg-indigo-700 disabled:opacity-60"
                                    >
                                        {busy ? <Loader2 className="h-4 w-4 animate-spin" /> : <KeyRound className="h-4 w-4" />}
                                        {t.saveKey}
                                    </button>
                                    <button
                                        type="button"
                                        disabled={busy || !item.has_api_key}
                                        onClick={() =>
                                            submitWithLock(provider, (opts) =>
                                                router.post(
                                                    route('ai-settings.check', provider),
                                                    { provider },
                                                    opts,
                                                ),
                                            )
                                        }
                                        className="inline-flex h-9 items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 text-sm font-medium text-slate-700 transition hover:bg-slate-50 disabled:opacity-60"
                                    >
                                        <PlugZap className="h-4 w-4" />
                                        {t.check}
                                    </button>
                                </div>

                                <div className="space-y-2 pt-1">
                                    <label className="block text-sm font-medium text-slate-700">{t.modelSelect}</label>
                                    <select
                                        value={selectedModel}
                                        onChange={(e) =>
                                            setSelectedModels((prev) => ({
                                                ...prev,
                                                [provider]: e.target.value,
                                            }))
                                        }
                                        className="block h-10 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-100"
                                        disabled={models.length === 0 || busy}
                                    >
                                        <option value="">{t.noModels}</option>
                                        {models.map((model) => (
                                            <option key={model.id} value={model.id}>
                                                {model.name ?? model.id}
                                            </option>
                                        ))}
                                    </select>
                                </div>

                                <button
                                    type="button"
                                    disabled={busy || !selectedModel}
                                    onClick={() =>
                                        submitWithLock(provider, (opts) =>
                                            router.post(
                                                route('ai-settings.activate', provider),
                                                {
                                                    provider,
                                                    model: selectedModel,
                                                },
                                                opts,
                                            ),
                                        )
                                    }
                                    className="inline-flex h-9 items-center gap-2 rounded-lg bg-emerald-600 px-3 text-sm font-medium text-white transition hover:bg-emerald-700 disabled:opacity-60"
                                >
                                    <CheckCircle2 className="h-4 w-4" />
                                    {t.activate}
                                </button>

                                {item.active_model && (
                                    <p className="text-xs text-slate-500">
                                        {t.activeModel}:{' '}
                                        <span className="font-medium text-slate-700">{item.active_model}</span>
                                    </p>
                                )}
                                {item.last_error && (
                                    <div className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">
                                        {item.last_error}
                                    </div>
                                )}
                            </div>
                        </div>
                    );
                })}
            </div>

            {errors.ai ? <p className="mt-4 text-sm text-red-600">{errors.ai}</p> : null}
        </div>
    );
}
