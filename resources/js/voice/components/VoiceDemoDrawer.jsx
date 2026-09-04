import { VOICE_STATES } from '../visualization/VoiceVisualizationState';

export default function VoiceDemoDrawer({ enabled, state, onState, onStartMic }) {
    if (! enabled) {
        return null;
    }

    return (
        <details className="jarvis-voice-demo">
            <summary>Demo visualization</summary>
            <p className="jarvis-voice-demo__hint">
                Local visual mode. Not speech. Not TTS. Cycle states without providers.
            </p>
            <div className="jarvis-voice-demo__grid">
                {VOICE_STATES.map((item) => (
                    <button
                        key={item}
                        type="button"
                        className={item === state ? 'is-active' : ''}
                        onClick={() => onState(item)}
                    >
                        {item}
                    </button>
                ))}
            </div>
            {onStartMic ? (
                <button type="button" className="jarvis-voice-demo__mic" onClick={onStartMic}>
                    Enable mic for listening
                </button>
            ) : null}
        </details>
    );
}
