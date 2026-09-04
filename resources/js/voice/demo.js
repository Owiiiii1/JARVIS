import { VOICE_STATES } from './visualization/VoiceVisualizationState';

export function isVoiceDemoEnabled() {
    if (typeof window !== 'undefined') {
        const query = new URLSearchParams(window.location.search);
        if (query.get('voice_demo') === '1') {
            return true;
        }
    }

    const flag = import.meta.env.VITE_VOICE_DEMO_MODE;

    return flag === 'true' || flag === '1';
}

export { VOICE_STATES };
