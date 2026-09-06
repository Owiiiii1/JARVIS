import { useForm } from '@inertiajs/react';
import SettingsCard from '@/personal-workspace/settings/SettingsCard';
import { workspaceRoute } from '@/personal-workspace/named';

const VOICE_STYLE_LABELS = {
    playful_warm: 'playful, bright, warm',
    calm_confident: 'calm, reassuring, confident',
    velvety_expressive: 'velvety and expressive',
    smooth_trustworthy: 'smooth and trustworthy',
    warm_storyteller: 'warm storyteller',
    natural_friendly: 'natural and friendly',
};

export default function VoiceSettings({ surface, user, settings }) {
    const voiceOptions = settings.voice?.voices ?? [];
    const femaleVoices = voiceOptions.filter((option) => option.gender === 'female');
    const maleVoices = voiceOptions.filter((option) => option.gender === 'male');
    const profileForm = useForm({
        name: settings.name ?? user.name ?? '',
        timezone: settings.timezone ?? user.timezone ?? '',
        voice_id: settings.voice?.voice_id ?? '',
    });

    return (
        <SettingsCard
            title="Голос ассистента"
            description="Выбор из текущего каталога. Ключи провайдеров остаются в Admin."
        >
            {voiceOptions.length === 0 ? (
                <p className="text-sm text-slate-400">Каталог голосов пока недоступен.</p>
            ) : (
                <form
                    className="space-y-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        profileForm.patch(workspaceRoute(surface, 'settings.profile.update'), {
                            preserveScroll: true,
                        });
                    }}
                >
                    <label className="text-xs uppercase tracking-[0.14em] text-slate-500" htmlFor="workspace-voice">
                        Голос
                    </label>
                    <select
                        id="workspace-voice"
                        value={profileForm.data.voice_id}
                        onChange={(event) => profileForm.setData('voice_id', event.target.value)}
                        className="mt-1 w-full rounded-lg border border-white/10 bg-black/30 px-3 py-2 text-sm text-slate-100 outline-none focus:border-sky-400/40"
                    >
                        <optgroup label="Женские">
                            {femaleVoices.map((option) => (
                                <option key={option.id} value={option.id}>
                                    {option.name} — {VOICE_STYLE_LABELS[option.style] ?? option.style}
                                </option>
                            ))}
                        </optgroup>
                        <optgroup label="Мужские">
                            {maleVoices.map((option) => (
                                <option key={option.id} value={option.id}>
                                    {option.name} — {VOICE_STYLE_LABELS[option.style] ?? option.style}
                                </option>
                            ))}
                        </optgroup>
                    </select>
                    {profileForm.errors.voice_id ? <p className="text-xs text-red-400">{profileForm.errors.voice_id}</p> : null}
                    <button
                        type="submit"
                        disabled={profileForm.processing}
                        className="rounded-lg bg-sky-500 px-3 py-2 text-sm font-medium text-white hover:bg-sky-400 disabled:opacity-60"
                    >
                        Сохранить голос
                    </button>
                </form>
            )}
        </SettingsCard>
    );
}
