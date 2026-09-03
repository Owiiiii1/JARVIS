import AdminLayout from '@/Layouts/AdminLayout';
import { Head, useForm, usePage } from '@inertiajs/react';
import { Lock, Save, Trash2 } from 'lucide-react';
import { useState } from 'react';

export default function EditProfile() {
    const { auth, locale = 'en' } = usePage().props;
    const user = auth?.user;
    const [showDeleteConfirm, setShowDeleteConfirm] = useState(false);
    const [showPasswordModal, setShowPasswordModal] = useState(false);

    const profileForm = useForm({
        name: user?.name ?? '',
        email: user?.email ?? '',
    });
    const passwordForm = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });
    const deleteForm = useForm({ password: '' });

    const text = {
        en: {
            title: 'Profile',
            subtitle: 'Manage your account details and security settings.',
            fullName: 'Full name',
            email: 'Email address',
            saveProfile: 'Save profile',
            security: 'Security',
            currentPassword: 'Current password',
            newPassword: 'New password',
            confirmPassword: 'Confirm password',
            changePassword: 'Change password',
            dangerZone: 'Danger zone',
            dangerText: 'Delete account permanently. This action cannot be undone.',
            deleteAccount: 'Delete account',
            cancel: 'Cancel',
            confirmDelete: 'Confirm deletion',
        },
        ru: {
            title: 'Профиль',
            subtitle: 'Управляйте данными аккаунта и настройками безопасности.',
            fullName: 'Полное имя',
            email: 'Email',
            saveProfile: 'Сохранить профиль',
            security: 'Безопасность',
            currentPassword: 'Текущий пароль',
            newPassword: 'Новый пароль',
            confirmPassword: 'Подтверждение пароля',
            changePassword: 'Изменить пароль',
            dangerZone: 'Опасная зона',
            dangerText: 'Удаление аккаунта навсегда. Это действие нельзя отменить.',
            deleteAccount: 'Удалить аккаунт',
            cancel: 'Отмена',
            confirmDelete: 'Подтвердить удаление',
        },
        uk: {
            title: 'Профіль',
            subtitle: 'Керуйте даними акаунта та налаштуваннями безпеки.',
            fullName: "Повне ім'я",
            email: 'Email',
            saveProfile: 'Зберегти профіль',
            security: 'Безпека',
            currentPassword: 'Поточний пароль',
            newPassword: 'Новий пароль',
            confirmPassword: 'Підтвердження пароля',
            changePassword: 'Змінити пароль',
            dangerZone: 'Небезпечна зона',
            dangerText: 'Видалення акаунта назавжди. Цю дію не можна скасувати.',
            deleteAccount: 'Видалити акаунт',
            cancel: 'Скасувати',
            confirmDelete: 'Підтвердити видалення',
        },
    };
    const t = text[locale] ?? text.en;

    return (
        <AdminLayout title={t.title}>
            <Head title="Profile" />

            <div className="space-y-6">
                <p className="text-sm text-slate-500">{t.subtitle}</p>

                <div className="app-widget p-5">
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            profileForm.patch(route('profile.update'), { preserveScroll: true });
                        }}
                        className="space-y-4"
                    >
                        <Field form={profileForm} field="name" label={t.fullName} />
                        <Field form={profileForm} field="email" label={t.email} type="email" />

                        <button
                            type="submit"
                            disabled={profileForm.processing}
                            className="inline-flex h-10 items-center gap-2 rounded-lg bg-indigo-600 px-4 text-sm font-semibold text-white transition hover:bg-indigo-700 disabled:opacity-60"
                        >
                            <Save className="h-4 w-4" />
                            {t.saveProfile}
                        </button>
                    </form>
                </div>

                <div className="app-widget p-5">
                    <h3 className="text-sm font-semibold uppercase tracking-wide text-slate-500">{t.security}</h3>
                    <button
                        type="button"
                        onClick={() => setShowPasswordModal(true)}
                        className="mt-3 inline-flex h-10 items-center gap-2 rounded-lg bg-slate-900 px-4 text-sm font-semibold text-white transition hover:bg-slate-800"
                    >
                        <Lock className="h-4 w-4" />
                        {t.changePassword}
                    </button>
                </div>

                <div className="app-widget p-5">
                    <h3 className="text-sm font-semibold uppercase tracking-wide text-red-600">{t.dangerZone}</h3>
                    <p className="mt-2 text-sm text-slate-500">{t.dangerText}</p>

                    {!showDeleteConfirm ? (
                        <button
                            type="button"
                            onClick={() => setShowDeleteConfirm(true)}
                            className="mt-4 inline-flex h-10 items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-4 text-sm font-semibold text-red-700 transition hover:bg-red-100"
                        >
                            <Trash2 className="h-4 w-4" />
                            {t.deleteAccount}
                        </button>
                    ) : (
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                deleteForm.delete(route('profile.destroy'), {
                                    preserveScroll: true,
                                });
                            }}
                            className="mt-4 space-y-3"
                        >
                            <Field form={deleteForm} field="password" label={t.currentPassword} type="password" />
                            <div className="flex gap-2">
                                <button
                                    type="button"
                                    onClick={() => {
                                        setShowDeleteConfirm(false);
                                        deleteForm.reset();
                                    }}
                                    className="inline-flex h-10 items-center rounded-lg border border-slate-300 px-4 text-sm font-medium text-slate-700 hover:bg-slate-50"
                                >
                                    {t.cancel}
                                </button>
                                <button
                                    type="submit"
                                    disabled={deleteForm.processing}
                                    className="inline-flex h-10 items-center gap-2 rounded-lg bg-red-600 px-4 text-sm font-semibold text-white transition hover:bg-red-700 disabled:opacity-60"
                                >
                                    <Trash2 className="h-4 w-4" />
                                    {t.confirmDelete}
                                </button>
                            </div>
                        </form>
                    )}
                </div>
            </div>

            {showPasswordModal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4">
                    <div className="w-full max-w-lg rounded-xl border border-slate-200 bg-white p-6 shadow-xl">
                        <h3 className="text-base font-semibold text-slate-900">{t.security}</h3>
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                passwordForm.put(route('password.update'), {
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        passwordForm.reset();
                                        setShowPasswordModal(false);
                                    },
                                });
                            }}
                            className="mt-4 space-y-4"
                        >
                            <Field form={passwordForm} field="current_password" label={t.currentPassword} type="password" />
                            <Field form={passwordForm} field="password" label={t.newPassword} type="password" />
                            <Field form={passwordForm} field="password_confirmation" label={t.confirmPassword} type="password" />

                            <div className="flex justify-end gap-2">
                                <button
                                    type="button"
                                    onClick={() => {
                                        setShowPasswordModal(false);
                                        passwordForm.reset();
                                    }}
                                    className="inline-flex h-10 items-center rounded-lg border border-slate-300 px-4 text-sm font-medium text-slate-700 hover:bg-slate-50"
                                >
                                    {t.cancel}
                                </button>
                                <button
                                    type="submit"
                                    disabled={passwordForm.processing}
                                    className="inline-flex h-10 items-center gap-2 rounded-lg bg-slate-900 px-4 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:opacity-60"
                                >
                                    <Lock className="h-4 w-4" />
                                    {t.changePassword}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </AdminLayout>
    );
}

function Field({ form, field, label, type = 'text' }) {
    return (
        <div>
            <label className="mb-1 block text-sm font-medium text-slate-600">{label}</label>
            <input
                type={type}
                value={form.data[field]}
                onChange={(e) => form.setData(field, e.target.value)}
                className="block h-11 w-full rounded-lg border border-slate-300 px-3 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-100"
            />
            {form.errors[field] && <p className="mt-2 text-sm text-red-600">{form.errors[field]}</p>}
        </div>
    );
}
