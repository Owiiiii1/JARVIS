import AdminLayout from '@/Layouts/AdminLayout';
import { Head, Link, usePage } from '@inertiajs/react';

function formatStamp(iso, timezone) {
    if (!iso) {
        return '—';
    }

    try {
        return new Date(iso).toLocaleString(undefined, {
            timeZone: timezone || undefined,
            dateStyle: 'medium',
            timeStyle: 'short',
        });
    } catch {
        return iso;
    }
}

export default function TelegramGroupsIndex() {
    const { groups = [], locale = 'en' } = usePage().props;
    const t = locale === 'ru'
        ? {
            title: 'Telegram Groups',
            empty: 'Пока нет групп. Добавьте бота в Telegram-группу — она появится после первого сообщения.',
            open: 'Open',
            messages: 'Messages',
            firstSeen: 'First seen',
            lastMessage: 'Last message',
            timezone: 'Timezone',
            fallback: 'owner fallback',
            mode: 'Mode',
        }
        : {
            title: 'Telegram Groups',
            empty: 'No groups yet. Add the bot to a Telegram group — it will appear after the first update.',
            open: 'Open',
            messages: 'Messages',
            firstSeen: 'First seen',
            lastMessage: 'Last message',
            timezone: 'Timezone',
            fallback: 'owner fallback',
            mode: 'Mode',
        };

    return (
        <AdminLayout title={t.title}>
            <Head title={t.title} />
            {groups.length === 0 ? (
                <p className="text-sm text-slate-600">{t.empty}</p>
            ) : (
                <div className="overflow-x-auto">
                    <table className="min-w-full text-left text-sm">
                        <thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th className="px-3 py-2 font-medium">Title</th>
                                <th className="px-3 py-2 font-medium">Type</th>
                                <th className="px-3 py-2 font-medium">Status</th>
                                <th className="px-3 py-2 font-medium">{t.messages}</th>
                                <th className="px-3 py-2 font-medium">{t.firstSeen}</th>
                                <th className="px-3 py-2 font-medium">{t.lastMessage}</th>
                                <th className="px-3 py-2 font-medium">{t.timezone}</th>
                                <th className="px-3 py-2 font-medium">{t.mode}</th>
                                <th className="px-3 py-2 font-medium" />
                            </tr>
                        </thead>
                        <tbody>
                            {groups.map((group) => (
                                <tr key={group.id} className="border-b border-slate-100">
                                    <td className="px-3 py-3 font-medium text-slate-900">{group.title}</td>
                                    <td className="px-3 py-3 text-slate-600">{group.chat_type}</td>
                                    <td className="px-3 py-3">
                                        <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700">
                                            {group.status}
                                        </span>
                                    </td>
                                    <td className="px-3 py-3 text-slate-700">{group.message_count}</td>
                                    <td className="px-3 py-3 text-slate-600">
                                        {formatStamp(group.first_seen_at, group.effective_timezone)}
                                    </td>
                                    <td className="px-3 py-3 text-slate-600">
                                        {formatStamp(group.last_message_at, group.effective_timezone)}
                                    </td>
                                    <td className="px-3 py-3 text-slate-600">
                                        {group.effective_timezone}
                                        {group.timezone_is_fallback ? (
                                            <span className="ml-1 text-xs text-slate-400">({t.fallback})</span>
                                        ) : null}
                                    </td>
                                    <td className="px-3 py-3 text-slate-600">Persist only</td>
                                    <td className="px-3 py-3 text-right">
                                        <Link
                                            href={route('telegram-groups.show', group.id)}
                                            className="text-sm font-medium text-amber-800 hover:text-amber-950"
                                        >
                                            {t.open}
                                        </Link>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </AdminLayout>
    );
}
