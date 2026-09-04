import { useEffect, useRef } from 'react';
import { canCreateOrbEngine, OrbEngine } from './OrbEngine';
import { prefersReducedMotion } from './capabilities';
import { createVoiceVisualizationState } from './VoiceVisualizationState';
import CssFallbackOrb from './CssFallbackOrb';

export default function JarvisVoiceOrb({ visualization, visualizationRef, fallbackState }) {
    const hostRef = useRef(null);
    const engineRef = useRef(null);
    const webgl = canCreateOrbEngine();
    const cssState = fallbackState ?? visualization?.state ?? visualizationRef?.current?.state ?? 'idle';

    useEffect(() => {
        if (! webgl || ! hostRef.current) {
            return undefined;
        }

        const engine = new OrbEngine(hostRef.current, { sourceRef: visualizationRef });
        engineRef.current = engine;
        const initial = visualizationRef?.current ?? visualization ?? {};
        engine.setVisualization({
            ...createVoiceVisualizationState(initial),
            reducedMotion: prefersReducedMotion() || Boolean(initial.reducedMotion),
        });

        return () => {
            engine.dispose();
            engineRef.current = null;
        };
    }, [webgl, visualizationRef]);

    useEffect(() => {
        if (! visualization || visualizationRef) {
            return;
        }

        engineRef.current?.setVisualization({
            ...createVoiceVisualizationState(visualization),
            reducedMotion: prefersReducedMotion() || Boolean(visualization.reducedMotion),
        });
    }, [visualization, visualizationRef]);

    if (! webgl) {
        return <CssFallbackOrb visualization={createVoiceVisualizationState({ state: cssState })} />;
    }

    return (
        <div className="jarvis-voice-orb-stage" aria-hidden="true">
            <div ref={hostRef} className="jarvis-voice-orb-canvas" />
        </div>
    );
}
