import AdminLayout from '@/Layouts/AdminLayout';
import { Head, Link, usePage } from '@inertiajs/react';

export default function UserMemory() {
    const {
        locale = 'en',
        user,
        profile,
        topics = [],
        memories = [],
        summaries = [],
        active_count = 0,
    } = usePage().props;

    const text = {
        en: {
            title: 'User memory',
            back: 'Back to users',
            profile: 'Profile',
            topics: 'Topics',
            memories: 'Memories',
            summaries: 'Conversation summaries',
            empty: 'None yet.',
            confidence: 'Confidence',
            status: 'Status',
            kind: 'Kind',
            sources: 'Sources',
            revisions: 'Revisions',
        },
        ru: {
            title: 'User memory',
            back: 'Back to users',
            profile: 'Profile',
            topics: 'Topics',
            memories: 'Memories',
            summaries: 'Conversation summaries',
            empty: 'None yet.',
            confidence: 'Confidence',
            status: 'Status',
            kind: 'Kind',
            sources: 'Sources',
            revisions: 'Revisions',
        },
        uk: {
            title: 'User memory',
            back: 'Back to users',
            profile: 'Profile',
            topics: 'Topics',
            memories: 'Memories',
            summaries: 'Conversation summaries',
            empty: 'None yet.',
            confidence: 'Confidence',
            status: 'Status',
            kind: 'Kind',
            sources: 'Sources',
            revisions: 'Revisions',
        },
    };
    const t = text[locale] ?? text.en;

    return (
        <AdminLayout title={t.title}>
            <Head title={`${t.title} · ${user?.name ?? ''}`} />

            <div className="space-y-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold text-slate-900">
                            {user?.name} · {t.title}
                        </h1>
                        <p className="mt-1 text-sm text-slate-600">
                            {user?.email} · active memories: {active_count}
                        </p>
                    </div>
                    <Link
                        href={route('settings.index', { tab: 'users' })}
                        className="inline-flex h-10 items-center rounded-lg border border-slate-300 px-4 text-sm font-medium text-slate-700 hover:bg-slate-50"
                    >
                        {t.back}
                    </Link>
                </div>

                <section className="rounded-xl border border-slate-200 bg-white p-4">
                    <h2 className="text-sm font-semibold text-slate-900">{t.profile}</h2>
                    <p className="mt-2 whitespace-pre-wrap text-sm text-slate-700">{profile || t.empty}</p>
                </section>

                <section className="rounded-xl border border-slate-200 bg-white p-4">
                    <h2 className="text-sm font-semibold text-slate-900">{t.topics}</h2>
                    {topics.length === 0 ? (
                        <p className="mt-2 text-sm text-slate-500">{t.empty}</p>
                    ) : (
                        <ul className="mt-2 space-y-1 text-sm text-slate-700">
                            {topics.map((topic) => (
                                <li key={topic.id}>
                                    {topic.name} <span className="text-slate-400">({topic.status})</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section className="rounded-xl border border-slate-200 bg-white p-4">
                    <h2 className="text-sm font-semibold text-slate-900">{t.memories}</h2>
                    {memories.length === 0 ? (
                        <p className="mt-2 text-sm text-slate-500">{t.empty}</p>
                    ) : (
                        <div className="mt-3 space-y-3">
                            {memories.map((memory) => (
                                <article key={memory.id} className="rounded-lg border border-slate-100 p-3">
                                    <p className="text-sm text-slate-900">{memory.content}</p>
                                    <p className="mt-1 text-xs text-slate-500">
                                        {t.kind}: {memory.kind} · {t.status}: {memory.status} · {t.confidence}:{' '}
                                        {memory.confidence}
                                    </p>
                                    {memory.sources?.length > 0 && (
                                        <p className="mt-1 text-xs text-slate-500">
                                            {t.sources}:{' '}
                                            {memory.sources
                                                .map((source) => `${source.source_kind}#${source.message_id ?? 'n/a'}`)
                                                .join(', ')}
                                        </p>
                                    )}
                                    {memory.revisions?.length > 0 && (
                                        <ul className="mt-2 space-y-1 text-xs text-slate-500">
                                            {memory.revisions.map((revision) => (
                                                <li key={revision.id}>
                                                    {t.revisions}: {revision.previous_status} → {revision.new_status} ·{' '}
                                                    {revision.reason}
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </article>
                            ))}
                        </div>
                    )}
                </section>

                <section className="rounded-xl border border-slate-200 bg-white p-4">
                    <h2 className="text-sm font-semibold text-slate-900">{t.summaries}</h2>
                    {summaries.length === 0 ? (
                        <p className="mt-2 text-sm text-slate-500">{t.empty}</p>
                    ) : (
                        <div className="mt-3 space-y-3">
                            {summaries.map((summary) => (
                                <article key={summary.id} className="rounded-lg border border-slate-100 p-3">
                                    <p className="text-xs font-medium text-slate-600">
                                        {summary.conversation_title} · v{summary.version} · {summary.status}
                                    </p>
                                    <p className="mt-1 whitespace-pre-wrap text-sm text-slate-800">{summary.summary}</p>
                                </article>
                            ))}
                        </div>
                    )}
                </section>
            </div>
        </AdminLayout>
    );
}
