export default function OrbPlaceholder({ label = 'Voice mode coming next' }) {
    return (
        <div className="flex flex-col items-center justify-center gap-6 py-10">
            <div className="jarvis-orb" aria-hidden="true">
                <span className="jarvis-orb__core" />
                <span className="jarvis-orb__glow" />
            </div>
            <p className="text-sm font-medium tracking-wide text-slate-300">{label}</p>
            <p className="max-w-xs text-center text-xs leading-5 text-slate-500">
                Microphone, realtime voice, and the final Orb arrive in a later milestone. This layout is reserved.
            </p>
        </div>
    );
}
