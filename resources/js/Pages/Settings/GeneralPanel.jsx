import { usePage } from '@inertiajs/react';

export default function GeneralPanel() {
    const { locale = 'en' } = usePage().props;
    const text = {
        en: { body: 'General settings will appear here.' },
        ru: { body: 'General settings will appear here.' },
        uk: { body: 'General settings will appear here.' },
    };
    const t = text[locale] ?? text.en;

    return (
        <div className="app-widget p-4">
            <p className="text-sm text-slate-700">{t.body}</p>
        </div>
    );
}
