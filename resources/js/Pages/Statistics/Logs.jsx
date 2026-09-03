import AdminLayout from '@/Layouts/AdminLayout';
import { Head, usePage } from '@inertiajs/react';

export default function StatisticsLogs() {
    const { locale = 'en' } = usePage().props;

    const text = {
        en: {
            title: 'Logs',
            body: 'This is a placeholder logs page for the Statistics section.',
        },
        ru: {
            title: 'Логи',
            body: 'Это заглушка страницы логов в разделе статистики.',
        },
        uk: {
            title: 'Логи',
            body: 'Це заглушка сторінки логів у розділі статистики.',
        },
    };

    const t = text[locale] ?? text.en;

    return (
        <AdminLayout title={t.title}>
            <Head title="Logs" />
            <div className="app-widget p-4">
                <p className="text-sm text-slate-700">{t.body}</p>
            </div>
        </AdminLayout>
    );
}
