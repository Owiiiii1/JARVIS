export default function CssFallbackOrb({ visualization }) {
    const state = visualization?.state ?? 'idle';

    return (
        <div className="jarvis-voice-orb-stage jarvis-voice-orb-stage--fallback" aria-hidden="true">
            <div className={`jarvis-orb jarvis-orb--${state}`} data-voice-state={state}>
                <span className="jarvis-orb__core" />
                <span className="jarvis-orb__glow" />
                <span className="jarvis-orb__ring" />
            </div>
        </div>
    );
}
