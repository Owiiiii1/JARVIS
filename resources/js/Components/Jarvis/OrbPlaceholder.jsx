export default function OrbPlaceholder({ label = 'Voice', state = 'idle', hint }) {
    const stateClass = state ? `jarvis-orb--${state}` : 'jarvis-orb--idle';

    return (
        <div className="flex flex-col items-center justify-center gap-6 py-10">
            <div className={`jarvis-orb ${stateClass}`} aria-hidden="true" data-voice-state={state}>
                <span className="jarvis-orb__core" />
                <span className="jarvis-orb__glow" />
            </div>
            <p className="text-sm font-medium tracking-wide text-slate-300">{label}</p>
            {hint ? (
                <p className="max-w-xs text-center text-xs leading-5 text-slate-500">{hint}</p>
            ) : null}
        </div>
    );
}
