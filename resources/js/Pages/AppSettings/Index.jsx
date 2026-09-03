import AdminLayout from '@/Layouts/AdminLayout';
import { Head, usePage } from '@inertiajs/react';

export default function AppSettingsIndex() {
    const { locale = 'en' } = usePage().props;
    const text = {
        en: {
            title: 'App settings',
            body: 'This is a placeholder app settings page.',
        },
        ru: {
            title: 'Настройки приложения',
            body: 'Это заглушка страницы настроек приложения.',
        },
        uk: {
            title: 'Налаштування застосунку',
            body: 'Це заглушка сторінки налаштувань застосунку.',
        },
    };
    const t = text[locale] ?? text.en;

    return (
        <AdminLayout title={t.title}>
            <Head title="App Settings" />
            <div className="app-widget p-4">
                <p className="text-sm text-slate-700">{t.body}</p>
            </div>
        </AdminLayout>
    );
}
