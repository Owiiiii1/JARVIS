import { Link, useForm, usePage } from '@inertiajs/react';
import { Plus, X } from 'lucide-react';
import { useState } from 'react';

export default function UsersPanel() {
    const { locale = 'en', users = [], timezones = [] } = usePage().props;
    const [showCreateModal, setShowCreateModal] = useState(false);
    const zones = timezones.length ? timezones : ['Europe/Rome', 'UTC'];
    const createForm = useForm({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
        timezone: 'Europe/Rome',
    });

    const text = {
        en: {
            usersTitle: 'Users',
            usersDescription: 'Jarvis users created by Owner. No self-registration. Open a card to manage access.',
            addUser: 'Add user',
            colName: 'Name',
            colEmail: 'Email',
            colRole: 'Role',
            colAccessCode: 'Access code',
            colStatus: 'Status',
            colTelegram: 'Telegram',
            colChats: 'Chats',
            colMessages: 'Messages',
            colActivity: 'Last activity',
            colCreated: 'Created',
            empty: 'No users yet.',
            modalTitle: 'Create user',
            fieldName: 'Full name',
            fieldEmail: 'Email',
            fieldPassword: 'Password',
            fieldPasswordConfirm: 'Confirm password',
            fieldTimezone: 'Timezone',
            cancel: 'Cancel',
            save: 'Create user',
            telegramYes: 'yes',
            telegramNo: 'no',
        },
        ru: {
            usersTitle: 'Users',
            usersDescription: 'Пользователей Jarvis создаёт только Owner. Саморегистрации нет.',
            addUser: 'Add user',
            colName: 'Name',
            colEmail: 'Email',
            colRole: 'Role',
            colAccessCode: 'Access code',
            colStatus: 'Status',
            colTelegram: 'Telegram',
            colChats: 'Chats',
            colMessages: 'Messages',
            colActivity: 'Last activity',
            colCreated: 'Created',
            empty: 'No users yet.',
            modalTitle: 'Create user',
            fieldName: 'Full name',
            fieldEmail: 'Email',
            fieldPassword: 'Password',
            fieldPasswordConfirm: 'Confirm password',
            fieldTimezone: 'Timezone',
            cancel: 'Cancel',
            save: 'Create user',
            telegramYes: 'yes',
            telegramNo: 'no',
        },
        uk: {
            usersTitle: 'Users',
            usersDescription: 'Користувачів Jarvis створює лише Owner. Самореєстрації немає.',
            addUser: 'Add user',
            colName: 'Name',
            colEmail: 'Email',
            colRole: 'Role',
            colAccessCode: 'Access code',
            colStatus: 'Status',
            colTelegram: 'Telegram',
            colChats: 'Chats',
            colMessages: 'Messages',
            colActivity: 'Last activity',
            colCreated: 'Created',
            empty: 'No users yet.',
            modalTitle: 'Create user',
            fieldName: 'Full name',
            fieldEmail: 'Email',
            fieldPassword: 'Password',
            fieldPasswordConfirm: 'Confirm password',
            fieldTimezone: 'Timezone',
            cancel: 'Cancel',
            save: 'Create user',
            telegramYes: 'yes',
            telegramNo: 'no',
        },
    };
    const t = text[locale] ?? text.en;

    const formatWhen = (iso) => {
        if (! iso) return '—';
        try {
            return new Date(iso).toLocaleString();
        } catch {
            return iso;
        }
    };

    return (
        <div className="space-y-6">
            <div className="app-widget p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 className="text-base font-semibold text-slate-900">{t.usersTitle}</h2>
                        <p className="mt-1 text-sm text-slate-600">{t.usersDescription}</p>
                    </div>
                    <button
                        type="button"
                        onClick={() => setShowCreateModal(true)}
                        className="inline-flex h-10 items-center gap-2 rounded-lg bg-indigo-600 px-4 text-sm font-semibold text-white transition hover:bg-indigo-700"
                    >
                        <Plus className="h-4 w-4" />
                        {t.addUser}
                    </button>
                </div>

                <div className="mt-4 overflow-x-auto rounded-lg border border-slate-200 bg-white">
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                            <tr>
                                <th className="px-4 py-3 text-left font-semibold">{t.colName}</th>
                                <th className="px-4 py-3 text-left font-semibold">{t.colEmail}</th>
                                <th className="px-4 py-3 text-left font-semibold">{t.colRole}</th>
                                <th className="px-4 py-3 text-left font-semibold">{t.colStatus}</th>
                                <th className="px-4 py-3 text-left font-semibold">{t.colTelegram}</th>
                                <th className="px-4 py-3 text-left font-semibold">{t.colAccessCode}</th>
                                <th className="px-4 py-3 text-left font-semibold">{t.colChats}</th>
                                <th className="px-4 py-3 text-left font-semibold">{t.colMessages}</th>
                                <th className="px-4 py-3 text-left font-semibold">{t.colActivity}</th>
                                <th className="px-4 py-3 text-left font-semibold">{t.colCreated}</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 text-slate-700">
                            {users.length === 0 ? (
                                <tr>
                                    <td colSpan={10} className="px-4 py-6 text-center text-sm text-slate-400">{t.empty}</td>
                                </tr>
                            ) : (
                                users.map((user) => (
                                    <tr key={user.id} className="hover:bg-slate-50/60">
                                        <td className="px-4 py-3 font-medium text-slate-900">
                                            <Link href={route('settings.users.show', user.id)} className="text-indigo-700 hover:underline">
                                                {user.name}
                                            </Link>
                                        </td>
                                        <td className="px-4 py-3">{user.email}</td>
                                        <td className="px-4 py-3 capitalize">{user.role}</td>
                                        <td className="px-4 py-3 capitalize">{user.status}</td>
                                        <td className="px-4 py-3">{user.telegram?.connected ? t.telegramYes : t.telegramNo}</td>
                                        <td className="px-4 py-3 font-mono text-xs">{user.access_code}</td>
                                        <td className="px-4 py-3">{user.chats_count ?? 0}</td>
                                        <td className="px-4 py-3">{user.messages_count ?? 0}</td>
                                        <td className="px-4 py-3 text-slate-500">{formatWhen(user.last_activity_at)}</td>
                                        <td className="px-4 py-3 text-slate-500">{formatWhen(user.created_at)}</td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            {showCreateModal ? (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4">
                    <div className="w-full max-w-xl rounded-xl border border-slate-200 bg-white p-6 shadow-xl">
                        <div className="flex items-start justify-between">
                            <h3 className="text-base font-semibold text-slate-900">{t.modalTitle}</h3>
                            <button
                                type="button"
                                onClick={() => {
                                    setShowCreateModal(false);
                                    createForm.reset();
                                    createForm.clearErrors();
                                }}
                                className="inline-flex h-8 w-8 items-center justify-center rounded-full text-slate-400 hover:bg-slate-100"
                            >
                                <X className="h-4 w-4" />
                            </button>
                        </div>
                        <form
                            onSubmit={(event) => {
                                event.preventDefault();
                                createForm.post(route('settings.users.store'), {
                                    preserveScroll: true,
                                });
                            }}
                            className="mt-4 space-y-4"
                        >
                            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                <Field form={createForm} field="name" label={t.fieldName} />
                                <Field form={createForm} field="email" label={t.fieldEmail} type="email" />
                                <Field form={createForm} field="password" label={t.fieldPassword} type="password" />
                                <Field form={createForm} field="password_confirmation" label={t.fieldPasswordConfirm} type="password" />
                                <div>
                                    <label className="mb-1 block text-sm font-medium text-slate-600">{t.fieldTimezone}</label>
                                    <select
                                        value={createForm.data.timezone}
                                        onChange={(event) => createForm.setData('timezone', event.target.value)}
                                        className="block h-11 w-full rounded-lg border border-slate-300 px-3 text-sm"
                                    >
                                        {zones.map((zone) => (
                                            <option key={zone} value={zone}>{zone}</option>
                                        ))}
                                    </select>
                                    {createForm.errors.timezone ? <p className="mt-2 text-sm text-red-600">{createForm.errors.timezone}</p> : null}
                                </div>
                            </div>
                            <div className="flex justify-end gap-2">
                                <button type="button" onClick={() => setShowCreateModal(false)} className="inline-flex h-10 items-center rounded-lg border border-slate-300 px-4 text-sm">
                                    {t.cancel}
                                </button>
                                <button type="submit" disabled={createForm.processing} className="inline-flex h-10 items-center rounded-lg bg-indigo-600 px-4 text-sm font-semibold text-white disabled:opacity-60">
                                    {t.save}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            ) : null}
        </div>
    );
}

function Field({ form, field, label, type = 'text' }) {
    return (
        <div>
            <label className="mb-1 block text-sm font-medium text-slate-600">{label}</label>
            <input
                type={type}
                value={form.data[field]}
                onChange={(event) => form.setData(field, event.target.value)}
                className="block h-11 w-full rounded-lg border border-slate-300 px-3 text-sm"
            />
            {form.errors[field] ? <p className="mt-2 text-sm text-red-600">{form.errors[field]}</p> : null}
        </div>
    );
}
