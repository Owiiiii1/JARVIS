import { router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

export default function GoogleOAuthConfigForm() {
    const { integrations = {}, errors = {} } = usePage().props;
    const google = integrations.google_oauth ?? {};
    const [clientId, setClientId] = useState(google.client_id ?? '');
    const [clientSecret, setClientSecret] = useState('');
    const [redirectUri, setRedirectUri] = useState(google.redirect_uri ?? '');
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        setClientId(google.client_id ?? '');
        setRedirectUri(google.redirect_uri ?? '');
        setClientSecret('');
    }, [google.client_id, google.redirect_uri, google.client_secret_source, google.configured]);

    const secretHint = google.client_secret_source === 'admin'
        ? 'Secret saved'
        : google.client_secret_source === 'env'
            ? 'Using deployment configuration'
            : 'Not set';

    const save = (event) => {
        event.preventDefault();
        if (busy) {
            return;
        }

        setBusy(true);
        router.post(route('settings.integrations.google.update'), {
            client_id: clientId,
            client_secret: clientSecret,
            redirect_uri: redirectUri,
        }, {
            preserveScroll: true,
            onFinish: () => setBusy(false),
            onSuccess: () => setClientSecret(''),
        });
    };

    return (
        <form onSubmit={save} className="mt-4 space-y-3 border-t border-[#E6DCC8] pt-4">
            <h3 className="text-sm font-semibold text-slate-900">Google Configuration</h3>
            <label className="block text-sm text-slate-700">
                Client ID
                <input
                    type="text"
                    name="client_id"
                    autoComplete="off"
                    value={clientId}
                    onChange={(event) => setClientId(event.target.value)}
                    className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900"
                />
            </label>
            {errors.client_id ? <p className="text-sm text-red-700">{errors.client_id}</p> : null}
            {google.client_id_source === 'env' ? (
                <p className="text-xs text-slate-500">Using deployment configuration. Saving a Client ID here takes precedence.</p>
            ) : null}

            <label className="block text-sm text-slate-700">
                Client Secret
                <input
                    type="password"
                    name="client_secret"
                    autoComplete="new-password"
                    value={clientSecret}
                    onChange={(event) => setClientSecret(event.target.value)}
                    placeholder={google.has_client_secret ? '••••••••••••' : ''}
                    className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900"
                />
            </label>
            <p className="text-xs text-slate-500">{secretHint}. Leave blank to keep the current secret.</p>
            {errors.client_secret ? <p className="text-sm text-red-700">{errors.client_secret}</p> : null}

            <label className="block text-sm text-slate-700">
                Redirect URI
                <input
                    type="url"
                    name="redirect_uri"
                    autoComplete="off"
                    value={redirectUri}
                    onChange={(event) => setRedirectUri(event.target.value)}
                    placeholder={google.redirect_uri_default || ''}
                    className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900"
                />
            </label>
            {google.redirect_uri_source !== 'admin' ? (
                <p className="text-xs text-slate-500">
                    Effective default (readonly hint): {google.redirect_uri_effective}
                </p>
            ) : null}
            {errors.redirect_uri ? <p className="text-sm text-red-700">{errors.redirect_uri}</p> : null}

            <button
                type="submit"
                disabled={busy}
                className="inline-flex h-9 items-center rounded-lg bg-indigo-600 px-3 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-60"
            >
                Save Google configuration
            </button>
        </form>
    );
}
