import AdminLayout from '@/Layouts/AdminLayout';
import { Head, Link, usePage } from '@inertiajs/react';

export default function Dashboard() {
    const { locale = 'en', owlAdmin = {} } = usePage().props;
    const brandName = owlAdmin.brand_name ?? 'Jarvis';
    const text = {
        en: {
            title: 'Home',
            hello: `Good to have you back in ${brandName}.`,
            body: 'Open the calendar for your schedule, or settings for AI and Telegram.',
            calendar: 'Calendar',
            calendarHint: 'Your personal schedule',
            logs: 'Logs',
            logsHint: 'Recent activity and diagnostics',
            settings: 'Settings',
            settingsHint: 'AI, users and Telegram',
        },
        ru: {
            title: 'Главная',
            hello: `С возвращением в ${brandName}.`,
            body: 'Календарь — ваше расписание. Настройки — ИИ и Telegram.',
            calendar: 'Календарь',
            calendarHint: 'Личное расписание',
            logs: 'Логи',
            logsHint: 'Активность и диагностика',
            settings: 'Настройки',
            settingsHint: 'ИИ, пользователи и Telegram',
        },
        uk: {
            title: 'Головна',
            hello: `З поверненням у ${brandName}.`,
            body: 'Календар — ваш розклад. Налаштування — ШІ та Telegram.',
            calendar: 'Календар',
            calendarHint: 'Особистий розклад',
            logs: 'Логи',
            logsHint: 'Активність і діагностика',
            settings: 'Налаштування',
            settingsHint: 'ШІ, користувачі та Telegram',
        },
    };
    const t = text[locale] ?? text.en;

    const cards = [
        { href: route('calendar.index'), title: t.calendar, hint: t.calendarHint },
        { href: route('statistics.logs'), title: t.logs, hint: t.logsHint },
        { href: route('settings.index'), title: t.settings, hint: t.settingsHint },
    ];

    return (
        <AdminLayout title={t.title}>
            <Head title={`${brandName} · ${t.title}`} />
            <div className="space-y-6">
                <div className="rounded-xl border border-[#E6DCC8] bg-[#FBF8F1] p-5">
                    <p className="text-lg font-semibold text-slate-900">{t.hello}</p>
                    <p className="mt-1 text-sm text-slate-600">{t.body}</p>
                </div>
                <div className="grid gap-4 sm:grid-cols-3">
                    {cards.map((card) => (
                        <Link
                            key={card.href}
                            href={card.href}
                            className="rounded-xl border border-slate-200 bg-white p-4 transition hover:border-amber-300 hover:shadow-sm"
                        >
                            <p className="text-sm font-semibold text-slate-900">{card.title}</p>
                            <p className="mt-1 text-xs text-slate-500">{card.hint}</p>
                        </Link>
                    ))}
                </div>
            </div>
        </AdminLayout>
    );
}
