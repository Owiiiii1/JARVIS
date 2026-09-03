import { usePage } from '@inertiajs/react';

export default function AppPanel() {
    const { locale = 'en' } = usePage().props;
    const text = {
        en: { body: 'App settings placeholder.' },
        ru: { body: 'App settings placeholder.' },
        uk: { body: 'App settings placeholder.' },
    };
    const t = text[locale] ?? text.en;

    return (
        <div className="app-widget p-4">
            <p className="text-sm text-slate-700">{t.body}</p>
        </div>
    );
}
