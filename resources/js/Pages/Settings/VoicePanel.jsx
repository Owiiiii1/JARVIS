import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';

function statusClass(status) {
    if (status === 'ready') {
        return 'bg-emerald-100 text-emerald-700';
    }
    if (status === 'partial' || status === 'not_configured') {
        return 'bg-amber-100 text-amber-800';
    }

    return 'bg-slate-100 text-slate-700';
}

export default function VoicePanel() {
    const { voice = {}, locale = 'en', errors = {} } = usePage().props;
    const [busy, setBusy] = useState(null);
    const [elevenKey, setElevenKey] = useState('');
    const [form, setForm] = useState({
        stt_provider: voice.stt_provider ?? 'none',
        tts_provider: voice.tts_provider ?? 'none',
        spoken_style_enabled: Boolean(voice.spoken_style_enabled),
        elevenlabs_voice_id: voice.elevenlabs_voice_id ?? '',
    });

    const text = {
        en: {
            title: 'Voice / Speech',
            hint: 'Technical STT/TTS settings. This does not change Owner Conversation AI. No Test Connection. Telephony and the final Orb are out of scope.',
            status: 'Status',
            stt: 'STT provider',
            tts: 'TTS provider',
            none: 'Not configured',
            openai: 'OpenAI Whisper',
            elevenlabs: 'ElevenLabs',
            spoken: 'Spoken-style presentation hint',
            spokenHelp: 'Adds a bounded spoken-response hint. It is not a second personality prompt.',
            voiceId: 'ElevenLabs voice id (optional)',
            save: 'Save settings',
            sttConfigured: 'STT configured',
            ttsConfigured: 'TTS configured',
            openaiConfigured: 'OpenAI configured',
            elevenConfigured: 'ElevenLabs configured',
            elevenSection: 'ElevenLabs',
            elevenHelp: 'Encrypted key for TTS only. Conversation AI stays on the current AI provider settings.',
            elevenKey: 'API key (set or replace)',
            elevenSave: 'Save ElevenLabs key',
            elevenClear: 'Remove stored key',
            elevenEnv: 'Using env ELEVENLABS_API_KEY fallback. Saving a key here takes precedence.',
            elevenAdmin: 'Configured from Admin.',
            openaiHelp: 'Whisper uses the existing OpenAI key from AI provider settings. It does not go through Conversation AI chat.',
            yes: 'yes',
            no: 'no',
            limits: 'Hard bounds (config)',
            sttNote: 'A dedicated Gemini/other STT adapter is deferred to M23.1 if Whisper is not the production STT.',
        },
        ru: {
            title: 'Voice / Speech',
            hint: 'Технические настройки STT/TTS. Owner Conversation AI не меняется. Test Connection нет. Телефония и финальный Orb вне scope.',
            status: 'Status',
            stt: 'STT provider',
            tts: 'TTS provider',
            none: 'Not configured',
            openai: 'OpenAI Whisper',
            elevenlabs: 'ElevenLabs',
            spoken: 'Spoken-style presentation hint',
            spokenHelp: 'Ограниченная подсказка для устной речи. Это не второй personality prompt.',
            voiceId: 'ElevenLabs voice id (optional)',
            save: 'Save settings',
            sttConfigured: 'STT configured',
            ttsConfigured: 'TTS configured',
            openaiConfigured: 'OpenAI configured',
            elevenConfigured: 'ElevenLabs configured',
            elevenSection: 'ElevenLabs',
            elevenHelp: 'Зашифрованный ключ только для TTS. Conversation AI остаётся на текущих AI settings.',
            elevenKey: 'API key (set or replace)',
            elevenSave: 'Save ElevenLabs key',
            elevenClear: 'Remove stored key',
            elevenEnv: 'Используется env ELEVENLABS_API_KEY. Сохранённый здесь ключ имеет приоритет.',
            elevenAdmin: 'Configured from Admin.',
            openaiHelp: 'Whisper использует существующий OpenAI ключ из AI provider settings. Не через Conversation AI chat.',
            yes: 'yes',
            no: 'no',
            limits: 'Hard bounds (config)',
            sttNote: 'Отдельный Gemini/другой STT adapter отложен на M23.1, если Whisper не станет production STT.',
        },
        uk: {
            title: 'Voice / Speech',
            hint: 'Технічні налаштування STT/TTS. Owner Conversation AI не змінюється. Test Connection немає. Телефонія і фінальний Orb поза scope.',
            status: 'Status',
            stt: 'STT provider',
            tts: 'TTS provider',
            none: 'Not configured',
            openai: 'OpenAI Whisper',
            elevenlabs: 'ElevenLabs',
            spoken: 'Spoken-style presentation hint',
            spokenHelp: 'Обмежена підказка для усного мовлення. Це не другий personality prompt.',
            voiceId: 'ElevenLabs voice id (optional)',
            save: 'Save settings',
            sttConfigured: 'STT configured',
            ttsConfigured: 'TTS configured',
            openaiConfigured: 'OpenAI configured',
            elevenConfigured: 'ElevenLabs configured',
            elevenSection: 'ElevenLabs',
            elevenHelp: 'Зашифрований ключ лише для TTS. Conversation AI лишається на поточних AI settings.',
            elevenKey: 'API key (set or replace)',
            elevenSave: 'Save ElevenLabs key',
            elevenClear: 'Remove stored key',
            elevenEnv: 'Використовується env ELEVENLABS_API_KEY. Збережений тут ключ має пріоритет.',
            elevenAdmin: 'Configured from Admin.',
            openaiHelp: 'Whisper використовує наявний OpenAI ключ з AI provider settings. Не через Conversation AI chat.',
            yes: 'yes',
            no: 'no',
            limits: 'Hard bounds (config)',
            sttNote: 'Окремий Gemini/інший STT adapter відкладено на M23.1, якщо Whisper не стане production STT.',
        },
    };
    const t = text[locale] ?? text.en;
    const limits = voice.limits ?? {};

    const submit = (event) => {
        event.preventDefault();
        setBusy('save');
        router.post(route('settings.voice.update'), form, {
            preserveScroll: true,
            onFinish: () => setBusy(null),
        });
    };

    const saveKey = (event) => {
        event.preventDefault();
        setBusy('key');
        router.post(route('settings.voice.elevenlabs-key'), { elevenlabs_api_key: elevenKey }, {
            preserveScroll: true,
            onSuccess: () => setElevenKey(''),
            onFinish: () => setBusy(null),
        });
    };

    const clearKey = () => {
        setBusy('clear');
        router.post(route('settings.voice.elevenlabs-key.clear'), {}, {
            preserveScroll: true,
            onFinish: () => setBusy(null),
        });
    };

    return (
        <section className="space-y-6 rounded-xl border border-[#E6DCC8] bg-[#FBF8F1] p-4">
            <div className="flex items-start justify-between gap-3">
                <div>
                    <h2 className="text-base font-semibold text-slate-900">{t.title}</h2>
                    <p className="mt-1 text-sm text-slate-600">{t.hint}</p>
                </div>
                <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${statusClass(voice.status)}`}>
                    {voice.status_label ?? t.none}
                </span>
            </div>

            <form onSubmit={submit} className="space-y-4">
                <label className="block text-sm text-slate-700">
                    {t.stt}
                    <select
                        value={form.stt_provider}
                        onChange={(event) => setForm((current) => ({ ...current, stt_provider: event.target.value }))}
                        className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2"
                    >
                        <option value="none">{t.none}</option>
                        <option value="openai">{t.openai}</option>
                    </select>
                </label>
                <p className="text-xs text-slate-500">{t.openaiHelp}</p>
                <p className="text-xs text-slate-500">{t.sttNote}</p>

                <label className="block text-sm text-slate-700">
                    {t.tts}
                    <select
                        value={form.tts_provider}
                        onChange={(event) => setForm((current) => ({ ...current, tts_provider: event.target.value }))}
                        className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2"
                    >
                        <option value="none">{t.none}</option>
                        <option value="elevenlabs">{t.elevenlabs}</option>
                    </select>
                </label>

                <label className="flex items-center gap-2 text-sm text-slate-700">
                    <input
                        type="checkbox"
                        checked={form.spoken_style_enabled}
                        onChange={(event) => setForm((current) => ({ ...current, spoken_style_enabled: event.target.checked }))}
                    />
                    {t.spoken}
                </label>
                <p className="text-xs text-slate-500">{t.spokenHelp}</p>

                <label className="block text-sm text-slate-700">
                    {t.voiceId}
                    <input
                        value={form.elevenlabs_voice_id}
                        onChange={(event) => setForm((current) => ({ ...current, elevenlabs_voice_id: event.target.value }))}
                        className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2"
                    />
                </label>

                {errors.stt_provider && <p className="text-sm text-red-600">{errors.stt_provider}</p>}

                <button
                    type="submit"
                    disabled={busy !== null}
                    className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-60"
                >
                    {t.save}
                </button>
            </form>

            <dl className="grid gap-2 text-sm text-slate-700 sm:grid-cols-2">
                <div className="flex justify-between gap-3 rounded-lg bg-white/70 px-3 py-2">
                    <dt>{t.sttConfigured}</dt>
                    <dd>{voice.stt_configured ? t.yes : t.no}</dd>
                </div>
                <div className="flex justify-between gap-3 rounded-lg bg-white/70 px-3 py-2">
                    <dt>{t.ttsConfigured}</dt>
                    <dd>{voice.tts_configured ? t.yes : t.no}</dd>
                </div>
                <div className="flex justify-between gap-3 rounded-lg bg-white/70 px-3 py-2">
                    <dt>{t.openaiConfigured}</dt>
                    <dd>{voice.openai_configured ? t.yes : t.no}</dd>
                </div>
                <div className="flex justify-between gap-3 rounded-lg bg-white/70 px-3 py-2">
                    <dt>{t.elevenConfigured}</dt>
                    <dd>{voice.elevenlabs_configured ? t.yes : t.no}</dd>
                </div>
            </dl>

            <div className="space-y-3 rounded-lg border border-[#E6DCC8] bg-white/60 p-3">
                <h3 className="text-sm font-semibold text-slate-900">{t.elevenSection}</h3>
                <p className="text-sm text-slate-600">{t.elevenHelp}</p>
                {voice.elevenlabs_key_source === 'env' && <p className="text-xs text-slate-500">{t.elevenEnv}</p>}
                {voice.elevenlabs_key_source === 'admin' && <p className="text-xs text-slate-500">{t.elevenAdmin}</p>}
                <form onSubmit={saveKey} className="flex flex-wrap gap-2">
                    <input
                        type="password"
                        autoComplete="off"
                        value={elevenKey}
                        onChange={(event) => setElevenKey(event.target.value)}
                        placeholder={t.elevenKey}
                        className="min-w-[16rem] flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm"
                    />
                    <button type="submit" disabled={busy !== null || elevenKey.length < 8} className="rounded-lg bg-slate-900 px-3 py-2 text-sm text-white disabled:opacity-60">
                        {t.elevenSave}
                    </button>
                    <button type="button" disabled={busy !== null || !voice.elevenlabs_configured} onClick={clearKey} className="rounded-lg border border-slate-300 px-3 py-2 text-sm disabled:opacity-60">
                        {t.elevenClear}
                    </button>
                </form>
            </div>

            <div>
                <h3 className="text-sm font-semibold text-slate-900">{t.limits}</h3>
                <ul className="mt-2 grid gap-1 text-xs text-slate-600 sm:grid-cols-2">
                    {Object.entries(limits).map(([key, value]) => (
                        <li key={key}>{key}: {String(value)}</li>
                    ))}
                </ul>
            </div>
        </section>
    );
}
