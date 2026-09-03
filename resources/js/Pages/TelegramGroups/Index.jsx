import AdminLayout from '@/Layouts/AdminLayout';
import { Head, router, usePage } from '@inertiajs/react';

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
    const {
        groups = [],
        locale = 'en',
        archived = false,
        archivedCount = 0,
        activeCount = 0,
    } = usePage().props;
    const t = locale === 'ru'
        ? {
            title: 'Telegram Groups',
            archiveTitle: 'Архив групп',
            empty: 'Пока нет групп. Добавьте бота в Telegram-группу — она появится после первого сообщения.',
            emptyArchive: 'Нет архивных групп. Когда бот покинет чат, группа перейдёт сюда вместе с историей.',
            messages: 'Messages',
            firstSeen: 'First seen',
            lastMessage: 'Last message',
            timezone: 'Timezone',
            fallback: 'owner fallback',
            mode: 'Mode',
            active: 'Активные',
            archive: 'Архив',
        }
        : {
            title: 'Telegram Groups',
            archiveTitle: 'Group archive',
            empty: 'No groups yet. Add the bot to a Telegram group — it will appear after the first update.',
            emptyArchive: 'No archived groups. When the bot leaves a chat, that group moves here with its history.',
            messages: 'Messages',
            firstSeen: 'First seen',
            lastMessage: 'Last message',
            timezone: 'Timezone',
            fallback: 'owner fallback',
            mode: 'Mode',
            active: 'Active',
            archive: 'Archive',
        };

    const pageTitle = archived ? t.archiveTitle : t.title;

    return (
        <AdminLayout title={pageTitle}>
            <Head title={pageTitle} />
            <div className="mb-4 flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    onClick={() => router.visit(route('telegram-groups.index'))}
                    className={`inline-flex h-9 items-center rounded-lg px-3 text-sm font-medium ${
                        archived
                            ? 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50'
                            : 'bg-[#0B1220] text-white'
                    }`}
                >
                    {t.active}
                    <span className="ml-2 text-xs opacity-80">{activeCount}</span>
                </button>
                <button
                    type="button"
                    onClick={() => router.visit(route('telegram-groups.archive'))}
                    className={`inline-flex h-9 items-center rounded-lg px-3 text-sm font-medium ${
                        archived
                            ? 'bg-[#0B1220] text-white'
                            : 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50'
                    }`}
                >
                    {t.archive}
                    <span className="ml-2 text-xs opacity-80">{archivedCount}</span>
                </button>
            </div>
            {groups.length === 0 ? (
                <p className="text-sm text-slate-600">{archived ? t.emptyArchive : t.empty}</p>
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
                            </tr>
                        </thead>
                        <tbody>
                            {groups.map((group) => (
                                <tr
                                    key={group.id}
                                    className="cursor-pointer border-b border-slate-100 transition hover:bg-amber-50/60"
                                    tabIndex={0}
                                    onClick={() => router.visit(route('telegram-groups.show', group.id))}
                                    onKeyDown={(event) => {
                                        if (event.key === 'Enter' || event.key === ' ') {
                                            event.preventDefault();
                                            router.visit(route('telegram-groups.show', group.id));
                                        }
                                    }}
                                >
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
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </AdminLayout>
    );
}
