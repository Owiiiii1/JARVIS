import AdminLayout from '@/Layouts/AdminLayout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

export default function UserCard() {
    const { managedUser = {}, timezones = [], errors = {}, flash = {} } = usePage().props;
    const [tab, setTab] = useState('overview');
    const profileForm = useForm({
        name: managedUser.name ?? '',
        email: managedUser.email ?? '',
        timezone: managedUser.timezone ?? 'Europe/Rome',
    });
    const passwordForm = useForm({
        password: '',
        password_confirmation: '',
    });
    const promptForm = useForm({
        general_prompt: managedUser.general_prompt ?? '',
    });

    const telegram = managedUser.telegram ?? {};
    const counts = managedUser.counts ?? {};
    const chats = managedUser.chats ?? [];
    const zones = timezones.length ? timezones : (managedUser.timezones ?? []);

    const formatWhen = (iso) => {
        if (! iso) return '—';
        try {
            return new Date(iso).toLocaleString();
        } catch {
            return iso;
        }
    };

    const telegramLabel = telegram.connected
        ? (telegram.username ? `Linked (@${telegram.username})` : 'Linked')
        : 'Not linked';

    return (
        <AdminLayout title={managedUser.name || 'User'}>
            <Head title={managedUser.name || 'User'} />
            <div className="space-y-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <Link href={route('settings.index', { tab: 'users' })} className="text-sm text-indigo-700 hover:underline">
                            ← Users
                        </Link>
                        <h1 className="mt-2 text-xl font-semibold text-slate-900">{managedUser.name}</h1>
                        <p className="text-sm text-slate-600">{managedUser.email} · {managedUser.role} · {managedUser.status}</p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {managedUser.can_impersonate ? (
                            <button
                                type="button"
                                onClick={() => router.post(route('settings.users.impersonate', managedUser.id))}
                                className="inline-flex h-10 items-center rounded-lg bg-slate-900 px-4 text-sm font-semibold text-white hover:bg-slate-800"
                            >
                                Open as User
                            </button>
                        ) : null}
                        {managedUser.can_disable ? (
                            <button
                                type="button"
                                onClick={() => router.post(route('settings.users.status', managedUser.id), {
                                    status: managedUser.status === 'disabled' ? 'active' : 'disabled',
                                })}
                                className="inline-flex h-10 items-center rounded-lg border border-slate-300 px-4 text-sm font-medium text-slate-700 hover:bg-slate-50"
                            >
                                {managedUser.status === 'disabled' ? 'Enable' : 'Disable'}
                            </button>
                        ) : null}
                    </div>
                </div>

                {flash.success ? <p className="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{flash.success}</p> : null}
                {errors.status ? <p className="text-sm text-red-600">{errors.status}</p> : null}
                {errors.user_delete ? <p className="text-sm text-red-600">{errors.user_delete}</p> : null}

                <div className="flex flex-wrap gap-2 border-b border-slate-200 pb-2">
                    {['overview', 'access', 'chats', 'memory', 'assistant'].map((id) => (
                        <button
                            key={id}
                            type="button"
                            onClick={() => setTab(id)}
                            className={`rounded-lg px-3 py-1.5 text-sm font-medium capitalize ${tab === id ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100'}`}
                        >
                            {id}
                        </button>
                    ))}
                </div>

                {tab === 'overview' ? (
                    <div className="grid gap-4 lg:grid-cols-2">
                        <section className="space-y-4 rounded-xl border border-slate-200 bg-white p-4">
                            <h2 className="text-sm font-semibold text-slate-900">Profile</h2>
                            <form
                                className="space-y-3"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    profileForm.patch(route('settings.users.update', managedUser.id), { preserveScroll: true });
                                }}
                            >
                                <Field form={profileForm} field="name" label="Name" />
                                <Field form={profileForm} field="email" label="Email" type="email" />
                                <div>
                                    <label className="mb-1 block text-sm font-medium text-slate-600">Role</label>
                                    <div className="flex h-11 items-center rounded-lg border border-slate-200 bg-slate-50 px-3 text-sm capitalize">{managedUser.role}</div>
                                </div>
                                <div>
                                    <label className="mb-1 block text-sm font-medium text-slate-600">Timezone</label>
                                    <select
                                        value={profileForm.data.timezone}
                                        onChange={(event) => profileForm.setData('timezone', event.target.value)}
                                        className="block h-11 w-full rounded-lg border border-slate-300 px-3 text-sm"
                                    >
                                        {zones.map((zone) => (
                                            <option key={zone} value={zone}>{zone}</option>
                                        ))}
                                    </select>
                                </div>
                                <ReadOnly label="Status" value={managedUser.status} />
                                <ReadOnly label="Created" value={formatWhen(managedUser.created_at)} />
                                <ReadOnly label="Last activity" value={formatWhen(managedUser.last_activity_at)} />
                                <ReadOnly label="Onboarding" value={managedUser.assistant_profile?.onboarding_status} />
                                <ReadOnly label="Assistant name" value={managedUser.assistant_profile?.assistant_name || (managedUser.is_owner ? 'Jarvis' : '—')} />
                                <button type="submit" disabled={profileForm.processing} className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-60">
                                    Save profile
                                </button>
                            </form>
                        </section>
                        <section className="space-y-3 rounded-xl border border-slate-200 bg-white p-4">
                            <h2 className="text-sm font-semibold text-slate-900">Usage</h2>
                            <dl className="grid grid-cols-2 gap-2 text-sm">
                                <Stat label="Chats" value={counts.chats} />
                                <Stat label="Messages" value={counts.messages} />
                                <Stat label="Memories" value={counts.memories} />
                                <Stat label="Stored files" value={counts.stored_files} />
                                <Stat label="Reminders" value={counts.reminders} />
                                <Stat label="Voice sessions" value={counts.voice_sessions} />
                            </dl>
                            <p className="text-xs text-slate-500">Hard delete is not available. Disable the account to block access without destroying data.</p>
                        </section>
                    </div>
                ) : null}

                {tab === 'access' ? (
                    <div className="grid gap-4 lg:grid-cols-2">
                        <section className="space-y-3 rounded-xl border border-slate-200 bg-white p-4">
                            <h2 className="text-sm font-semibold text-slate-900">Telegram</h2>
                            <p className="text-sm text-slate-700">{telegramLabel}</p>
                            {telegram.display_name ? <p className="text-sm text-slate-600">{telegram.display_name}</p> : null}
                            <p className="text-xs text-slate-500">Linked at: {formatWhen(telegram.linked_at)}</p>
                            <ReadOnly label="Access code" value={managedUser.access_code} mono />
                            <p className="text-xs text-slate-500">Access code is for Telegram pairing only. Regenerating it does not unlink the current Telegram identity.</p>
                            <div className="flex flex-wrap gap-2">
                                {managedUser.can_regenerate_code ? (
                                    <button
                                        type="button"
                                        onClick={() => router.post(route('settings.users.access-code.regenerate', managedUser.id))}
                                        className="rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-2 text-xs font-medium text-indigo-700"
                                    >
                                        Regenerate Telegram Code
                                    </button>
                                ) : null}
                                {telegram.connected ? (
                                    <button
                                        type="button"
                                        onClick={() => router.post(route('settings.users.telegram.unlink', managedUser.id))}
                                        className="rounded-lg border border-slate-300 px-3 py-2 text-xs font-medium text-slate-700"
                                    >
                                        Unlink Telegram
                                    </button>
                                ) : null}
                            </div>
                            {errors.access_code ? <p className="text-sm text-red-600">{errors.access_code}</p> : null}
                        </section>
                        <section className="space-y-3 rounded-xl border border-slate-200 bg-white p-4">
                            <h2 className="text-sm font-semibold text-slate-900">Set password</h2>
                            <p className="text-xs text-slate-500">The new password is hashed. It cannot be recovered later. This user’s sessions are signed out.</p>
                            <form
                                className="space-y-3"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    passwordForm.post(route('settings.users.password', managedUser.id), {
                                        preserveScroll: true,
                                        onSuccess: () => passwordForm.reset(),
                                    });
                                }}
                            >
                                <Field form={passwordForm} field="password" label="New password" type="password" />
                                <Field form={passwordForm} field="password_confirmation" label="Confirm password" type="password" />
                                <button type="submit" disabled={passwordForm.processing || managedUser.is_owner} className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-60">
                                    Save password
                                </button>
                            </form>
                        </section>
                    </div>
                ) : null}

                {tab === 'chats' ? (
                    <section className="rounded-xl border border-slate-200 bg-white p-4">
                        <h2 className="text-sm font-semibold text-slate-900">Chats</h2>
                        <p className="mt-1 text-xs text-slate-500">Read-only metadata. Use Open as User to inspect the workspace.</p>
                        {chats.length === 0 ? (
                            <p className="mt-3 text-sm text-slate-500">No chats yet.</p>
                        ) : (
                            <table className="mt-3 min-w-full divide-y divide-slate-200 text-sm">
                                <thead className="text-left text-xs uppercase text-slate-500">
                                    <tr>
                                        <th className="py-2">Title</th>
                                        <th className="py-2">Messages</th>
                                        <th className="py-2">Last activity</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {chats.map((chat) => (
                                        <tr key={chat.id}>
                                            <td className="py-2">{chat.title}</td>
                                            <td className="py-2">{chat.messages_count ?? 0}</td>
                                            <td className="py-2 text-slate-500">{formatWhen(chat.last_activity_at)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </section>
                ) : null}

                {tab === 'memory' ? (
                    <section className="space-y-4 rounded-xl border border-slate-200 bg-white p-4">
                        <h2 className="text-sm font-semibold text-slate-900">Memory & General Prompt</h2>
                        <p className="text-sm text-slate-600">Active memories: {counts.active_memories ?? 0}</p>
                        <Link
                            href={route('settings.users.memory.show', managedUser.id)}
                            className="inline-flex h-10 items-center rounded-lg border border-slate-300 px-4 text-sm font-medium text-slate-700 hover:bg-slate-50"
                        >
                            Open memory diagnostics
                        </Link>
                        <form
                            className="space-y-3"
                            onSubmit={(event) => {
                                event.preventDefault();
                                promptForm.patch(route('settings.users.prompt', managedUser.id), { preserveScroll: true });
                            }}
                        >
                            <label className="block text-sm font-medium text-slate-600">
                                General Prompt
                                <textarea
                                    value={promptForm.data.general_prompt ?? ''}
                                    onChange={(event) => promptForm.setData('general_prompt', event.target.value)}
                                    rows={8}
                                    className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                                />
                            </label>
                            <button type="submit" disabled={promptForm.processing} className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-60">
                                Save General Prompt
                            </button>
                        </form>
                    </section>
                ) : null}

                {tab === 'assistant' ? (
                    <section className="space-y-3 rounded-xl border border-slate-200 bg-white p-4">
                        <h2 className="text-sm font-semibold text-slate-900">Assistant personalization</h2>
                        <p className="text-xs text-slate-500">Read-only inspection. Users change this in chat. General Prompt remains separate.</p>
                        <ReadOnly label="Onboarding status" value={managedUser.assistant_profile?.onboarding_status} />
                        <ReadOnly label="Assistant name" value={managedUser.assistant_profile?.assistant_name} />
                        <ReadOnly label="Completed at" value={formatWhen(managedUser.assistant_profile?.onboarding_completed_at)} />
                        <ReadOnly label="Personality" value={managedUser.assistant_profile?.personality} />
                        <ReadOnly label="Interaction style" value={managedUser.assistant_profile?.interaction_style} />
                        <ReadOnly label="About user" value={managedUser.assistant_profile?.about_user} />
                    </section>
                ) : null}
            </div>
        </AdminLayout>
    );
}

function Field({ form, field, label, type = 'text' }) {
    return (
        <div>
            <label className="mb-1 block text-sm font-medium text-slate-600">{label}</label>
            <input
                type={type}
                value={form.data[field] ?? ''}
                onChange={(event) => form.setData(field, event.target.value)}
                className="block h-11 w-full rounded-lg border border-slate-300 px-3 text-sm"
            />
            {form.errors[field] ? <p className="mt-1 text-sm text-red-600">{form.errors[field]}</p> : null}
        </div>
    );
}

function ReadOnly({ label, value, mono = false }) {
    return (
        <div>
            <label className="mb-1 block text-sm font-medium text-slate-600">{label}</label>
            <div className={`flex min-h-11 items-center rounded-lg border border-slate-200 bg-slate-50 px-3 text-sm text-slate-700 ${mono ? 'font-mono text-xs' : ''}`}>
                {value || '—'}
            </div>
        </div>
    );
}

function Stat({ label, value }) {
    return (
        <div className="rounded-lg bg-slate-50 px-3 py-2">
            <dt className="text-xs text-slate-500">{label}</dt>
            <dd className="text-base font-semibold text-slate-900">{value ?? 0}</dd>
        </div>
    );
}
