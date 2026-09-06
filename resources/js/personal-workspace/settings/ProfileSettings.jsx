import { Link, router, useForm } from '@inertiajs/react';
import SettingsCard from '@/personal-workspace/settings/SettingsCard';
import { workspaceRoute } from '@/personal-workspace/named';

export default function ProfileSettings({
    surface,
    user,
    settings,
    assistantProfile,
    capabilities,
    showOnboarding,
    onboardingLabel,
    onboardingStatus,
    onClose,
}) {
    const profileForm = useForm({
        name: settings.name ?? user.name ?? '',
        timezone: settings.timezone ?? user.timezone ?? '',
        voice_id: settings.voice?.voice_id ?? '',
    });
    const passwordForm = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    return (
        <div className="space-y-4">
            <SettingsCard title="Профиль" description="Имя, часовой пояс и статус знакомства с ассистентом.">
                <form
                    className="space-y-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        profileForm.patch(workspaceRoute(surface, 'settings.profile.update'), {
                            preserveScroll: true,
                        });
                    }}
                >
                    <div>
                        <label className="text-xs uppercase tracking-[0.14em] text-slate-500" htmlFor="workspace-email">
                            Email
                        </label>
                        <p id="workspace-email" className="mt-1 text-sm text-slate-200">{user.email || '—'}</p>
                    </div>
                    <div>
                        <label className="text-xs uppercase tracking-[0.14em] text-slate-500" htmlFor="workspace-name">
                            Имя
                        </label>
                        <input
                            id="workspace-name"
                            value={profileForm.data.name}
                            onChange={(event) => profileForm.setData('name', event.target.value)}
                            className="mt-1 w-full rounded-lg border border-white/10 bg-black/30 px-3 py-2 text-sm text-slate-100 outline-none focus:border-sky-400/40"
                        />
                    </div>
                    <div>
                        <label className="text-xs uppercase tracking-[0.14em] text-slate-500" htmlFor="workspace-timezone">
                            Часовой пояс
                        </label>
                        <select
                            id="workspace-timezone"
                            value={profileForm.data.timezone}
                            onChange={(event) => profileForm.setData('timezone', event.target.value)}
                            className="mt-1 w-full rounded-lg border border-white/10 bg-black/30 px-3 py-2 text-sm text-slate-100 outline-none focus:border-sky-400/40"
                        >
                            {(settings.timezones?.length ? settings.timezones : [profileForm.data.timezone || 'Europe/Rome']).map((zone) => (
                                <option key={zone} value={zone}>{zone}</option>
                            ))}
                        </select>
                    </div>
                    {showOnboarding ? (
                        <div className="rounded-xl border border-white/10 bg-black/20 px-3 py-3">
                            <p className="text-xs uppercase tracking-[0.14em] text-slate-500">Знакомство</p>
                            <p className="mt-1 text-sm text-slate-200">{onboardingLabel}</p>
                            {onboardingStatus === 'not_started' || onboardingStatus === 'in_progress' ? (
                                <button
                                    type="button"
                                    onClick={() => {
                                        onClose();
                                        router.post(workspaceRoute(surface, 'onboarding.start'));
                                    }}
                                    className="mt-2 rounded-lg bg-sky-500 px-3 py-1.5 text-xs font-medium text-white hover:bg-sky-400"
                                >
                                    {onboardingStatus === 'in_progress' ? 'Продолжить знакомство' : 'Познакомиться'}
                                </button>
                            ) : (
                                <p className="mt-2 text-xs text-slate-500">
                                    Профиль ассистента: {assistantProfile?.assistant_name || assistantProfile?.presentation_name || '—'}
                                </p>
                            )}
                        </div>
                    ) : (
                        <p className="text-xs text-slate-500">
                            Ассистент: {assistantProfile?.presentation_name || 'Jarvis'}
                        </p>
                    )}
                    <button
                        type="submit"
                        disabled={profileForm.processing}
                        className="rounded-lg bg-sky-500 px-3 py-2 text-sm font-medium text-white hover:bg-sky-400 disabled:opacity-60"
                    >
                        Сохранить профиль
                    </button>
                </form>
            </SettingsCard>

            <SettingsCard title="Пароль" description="Смена пароля для входа в веб-Workspace.">
                <div className="space-y-2">
                    <input
                        type="password"
                        placeholder="Текущий пароль"
                        value={passwordForm.data.current_password}
                        onChange={(event) => passwordForm.setData('current_password', event.target.value)}
                        className="w-full rounded-lg border border-white/10 bg-black/30 px-3 py-2 text-sm text-slate-100 outline-none"
                    />
                    <input
                        type="password"
                        placeholder="Новый пароль"
                        value={passwordForm.data.password}
                        onChange={(event) => passwordForm.setData('password', event.target.value)}
                        className="w-full rounded-lg border border-white/10 bg-black/30 px-3 py-2 text-sm text-slate-100 outline-none"
                    />
                    <input
                        type="password"
                        placeholder="Повторите пароль"
                        value={passwordForm.data.password_confirmation}
                        onChange={(event) => passwordForm.setData('password_confirmation', event.target.value)}
                        className="w-full rounded-lg border border-white/10 bg-black/30 px-3 py-2 text-sm text-slate-100 outline-none"
                    />
                    {passwordForm.errors.current_password ? <p className="text-xs text-red-400">{passwordForm.errors.current_password}</p> : null}
                    {passwordForm.errors.password ? <p className="text-xs text-red-400">{passwordForm.errors.password}</p> : null}
                    <button
                        type="button"
                        disabled={passwordForm.processing}
                        onClick={() => passwordForm.put(workspaceRoute(surface, 'settings.password.update'), {
                            preserveScroll: true,
                            onSuccess: () => passwordForm.reset(),
                        })}
                        className="rounded-lg border border-white/10 px-3 py-2 text-sm text-slate-200 hover:bg-white/5 disabled:opacity-60"
                    >
                        Обновить пароль
                    </button>
                </div>
            </SettingsCard>

            <SettingsCard>
                <div className="flex flex-wrap gap-2">
                    {capabilities.admin ? (
                        <Link
                            href={route('dashboard')}
                            className="rounded-lg border border-white/10 px-3 py-2 text-sm text-slate-200 hover:bg-white/5"
                        >
                            Admin
                        </Link>
                    ) : null}
                    <button
                        type="button"
                        onClick={() => router.post(route('logout'))}
                        className="rounded-lg border border-white/10 px-3 py-2 text-sm text-slate-200 hover:bg-white/5"
                    >
                        Выйти
                    </button>
                </div>
            </SettingsCard>
        </div>
    );
}
