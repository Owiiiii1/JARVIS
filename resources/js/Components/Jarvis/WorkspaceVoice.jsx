import { lazy, Suspense, useEffect, useState } from 'react';
import VoiceSession from '@/Components/Jarvis/VoiceSession';

const RealtimeVoiceSession = lazy(() => import('@/Components/Jarvis/RealtimeVoiceSession'));

const STORAGE_KEY = 'jarvis.voice.web_mode';

function readMode() {
    try {
        const value = window.localStorage.getItem(STORAGE_KEY);

        return value === 'realtime' ? 'realtime' : 'ptt';
    } catch {
        return 'ptt';
    }
}

function writeMode(mode) {
    try {
        window.localStorage.setItem(STORAGE_KEY, mode);
    } catch {
        // Preference is optional.
    }
}

export default function WorkspaceVoice({
    conversationId,
    surface,
    voiceClient,
    onSwitchToText,
    onTurn,
}) {
    const [mode, setMode] = useState(() => readMode());
    const realtime = voiceClient?.realtime ?? {};

    useEffect(() => {
        writeMode(mode);
    }, [mode]);

    return (
        <div className="flex h-full min-h-0 flex-col">
            <div className="jarvis-voice-mode-switch" role="tablist" aria-label="Режим голоса">
                <button
                    type="button"
                    role="tab"
                    aria-selected={mode === 'ptt'}
                    className={`jarvis-voice-mode-switch__btn ${mode === 'ptt' ? 'is-active' : ''}`}
                    onClick={() => setMode('ptt')}
                >
                    Рация
                </button>
                <button
                    type="button"
                    role="tab"
                    aria-selected={mode === 'realtime'}
                    className={`jarvis-voice-mode-switch__btn ${mode === 'realtime' ? 'is-active' : ''}`}
                    onClick={() => setMode('realtime')}
                >
                    Диалог Beta
                </button>
            </div>
            <div className="min-h-0 flex-1">
                {mode === 'realtime' ? (
                    <Suspense
                        fallback={
                            <div className="flex h-full items-center justify-center text-sm text-slate-400">
                                Loading Dialog…
                            </div>
                        }
                    >
                        <RealtimeVoiceSession
                            conversationId={conversationId}
                            surface={surface}
                            voiceClient={voiceClient}
                            realtime={realtime}
                            onSwitchToText={onSwitchToText}
                            onSwitchToPtt={() => setMode('ptt')}
                            onTurn={onTurn}
                        />
                    </Suspense>
                ) : (
                    <VoiceSession
                        conversationId={conversationId}
                        surface={surface}
                        voiceClient={voiceClient}
                        onSwitchToText={onSwitchToText}
                        onTurn={onTurn}
                    />
                )}
            </div>
        </div>
    );
}
