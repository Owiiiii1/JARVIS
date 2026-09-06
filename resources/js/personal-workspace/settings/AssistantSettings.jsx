import { useEffect } from 'react';
import { useForm } from '@inertiajs/react';
import SettingsCard from '@/personal-workspace/settings/SettingsCard';
import { workspaceRoute } from '@/personal-workspace/named';

export default function AssistantSettings({ surface, settings, assistantProfile, generalPrompt }) {
    const promptForm = useForm({
        general_prompt: generalPrompt ?? settings.general_prompt ?? '',
    });

    useEffect(() => {
        promptForm.setData('general_prompt', generalPrompt ?? settings.general_prompt ?? '');
    }, [generalPrompt, settings.general_prompt]);

    return (
        <div className="space-y-4">
            <SettingsCard
                title="Кто ассистент"
                description="Имя, характер и стиль задаются в чате. Здесь — текущее состояние, без отдельной формы-дубля."
            >
                <dl className="space-y-3 text-sm">
                    <div>
                        <dt className="text-xs uppercase tracking-[0.12em] text-slate-500">Имя</dt>
                        <dd className="mt-0.5 text-slate-100">{assistantProfile?.assistant_name || assistantProfile?.presentation_name || '—'}</dd>
                    </div>
                    <div>
                        <dt className="text-xs uppercase tracking-[0.12em] text-slate-500">Характер</dt>
                        <dd className="mt-0.5 whitespace-pre-wrap text-slate-200">{assistantProfile?.personality || 'Пока не задан. Напишите в чат, каким он должен быть.'}</dd>
                    </div>
                    <div>
                        <dt className="text-xs uppercase tracking-[0.12em] text-slate-500">Стиль взаимодействия</dt>
                        <dd className="mt-0.5 whitespace-pre-wrap text-slate-200">{assistantProfile?.interaction_style || 'Пока не задан.'}</dd>
                    </div>
                    <div>
                        <dt className="text-xs uppercase tracking-[0.12em] text-slate-500">О вас</dt>
                        <dd className="mt-0.5 whitespace-pre-wrap text-slate-200">{assistantProfile?.about_user || '—'}</dd>
                    </div>
                </dl>
                <p className="mt-3 text-[11px] leading-5 text-slate-500">
                    Это не Memory. Факты, накопленные со временем, живут в разделе Memory.
                </p>
            </SettingsCard>

            <SettingsCard
                title="User General Prompt"
                description="Явные дополнительные инструкции для этого аккаунта. Отдельно от личности ассистента и Memory."
            >
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        promptForm.patch(workspaceRoute(surface, 'settings.prompt.update'), {
                            preserveScroll: true,
                        });
                    }}
                >
                    <textarea
                        value={promptForm.data.general_prompt ?? ''}
                        onChange={(event) => promptForm.setData('general_prompt', event.target.value)}
                        rows={8}
                        className="w-full rounded-xl border border-white/10 bg-black/30 p-3 text-sm text-slate-100 outline-none focus:border-sky-400/40"
                    />
                    <div className="mt-3 flex justify-end">
                        <button
                            type="submit"
                            disabled={promptForm.processing}
                            className="rounded-lg bg-sky-500 px-3 py-2 text-sm font-medium text-white hover:bg-sky-400 disabled:opacity-60"
                        >
                            Сохранить
                        </button>
                    </div>
                </form>
            </SettingsCard>
        </div>
    );
}
