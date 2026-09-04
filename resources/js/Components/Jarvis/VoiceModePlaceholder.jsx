import OrbPlaceholder from '@/Components/Jarvis/OrbPlaceholder';
import { Mic, MicOff, PhoneOff, Type } from 'lucide-react';

/**
 * M23 boundary: replace this panel with <VoiceSession conversationId={id} />.
 * Keep the selected conversation id. Do not start a new conversation for voice.
 */
export default function VoiceModePlaceholder({ conversationId, onSwitchToText }) {
    return (
        <div className="flex h-full min-h-0 flex-col">
            <div className="flex min-h-0 flex-1 flex-col items-center justify-center px-6">
                <OrbPlaceholder />
            </div>

            <div className="border-t border-white/10 bg-black/20 px-4 py-4">
                <p className="mb-3 text-center text-xs text-slate-500">
                    Transcript will appear here. Conversation {conversationId ? `#${conversationId}` : ''} stays selected.
                </p>
                <div className="mx-auto flex max-w-md items-center justify-center gap-3">
                    <button
                        type="button"
                        disabled
                        className="inline-flex h-11 w-11 items-center justify-center rounded-full border border-white/10 text-slate-500 opacity-60"
                        aria-label="Mute (coming next)"
                    >
                        <MicOff className="h-4 w-4" />
                    </button>
                    <button
                        type="button"
                        disabled
                        className="inline-flex h-12 w-12 items-center justify-center rounded-full border border-white/10 bg-white/5 text-slate-500 opacity-60"
                        aria-label="Microphone (coming next)"
                    >
                        <Mic className="h-5 w-5" />
                    </button>
                    <button
                        type="button"
                        disabled
                        className="inline-flex h-11 w-11 items-center justify-center rounded-full border border-white/10 text-slate-500 opacity-60"
                        aria-label="End voice (coming next)"
                    >
                        <PhoneOff className="h-4 w-4" />
                    </button>
                    <button
                        type="button"
                        onClick={onSwitchToText}
                        className="inline-flex h-11 items-center gap-2 rounded-full border border-sky-400/30 bg-sky-400/10 px-4 text-sm font-medium text-sky-200 hover:bg-sky-400/20"
                        aria-label="Switch to text"
                    >
                        <Type className="h-4 w-4" />
                        Text
                    </button>
                </div>
            </div>
        </div>
    );
}
