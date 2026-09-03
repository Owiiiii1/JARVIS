import AdminLayout from '@/Layouts/AdminLayout';
import { Head, usePage } from '@inertiajs/react';

export default function CalendarIndex({ events = [] }) {
    const { locale = 'en' } = usePage().props;
    const text = {
        en: {
            title: 'Calendar',
            heading: 'Personal schedule',
            hint: 'Your events and reminders will appear here.',
            empty: 'No events scheduled yet.',
        },
        ru: {
            title: 'Календарь',
            heading: 'Личное расписание',
            hint: 'Здесь будут ваши события и напоминания.',
            empty: 'Пока нет запланированных событий.',
        },
        uk: {
            title: 'Календар',
            heading: 'Особистий розклад',
            hint: 'Тут з’являться ваші події та нагадування.',
            empty: 'Поки немає запланованих подій.',
        },
    };
    const t = text[locale] ?? text.en;

    return (
        <AdminLayout title={t.title}>
            <Head title={t.title} />

            <div className="space-y-4">
                <section className="rounded-xl border border-[#E6DCC8] bg-[#FBF8F1] p-4">
                    <h2 className="text-base font-semibold text-slate-900">{t.heading}</h2>
                    <p className="mt-1 text-sm text-slate-600">{t.hint}</p>
                </section>

                {events.length === 0 ? (
                    <section className="rounded-xl border border-slate-200 bg-white p-4">
                        <p className="text-sm text-slate-600">{t.empty}</p>
                    </section>
                ) : (
                    events.map((event) => (
                        <section key={event.id} className="rounded-xl border border-slate-200 bg-white p-4">
                            <h3 className="text-sm font-semibold text-slate-900">{event.title}</h3>
                            <p className="mt-1 text-sm text-slate-600">{event.scheduled_at}</p>
                        </section>
                    ))
                )}
            </div>
        </AdminLayout>
    );
}
