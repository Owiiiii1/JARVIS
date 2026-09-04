import * as THREE from 'three';
import { EffectComposer } from 'three/addons/postprocessing/EffectComposer.js';
import { RenderPass } from 'three/addons/postprocessing/RenderPass.js';
import { UnrealBloomPass } from 'three/addons/postprocessing/UnrealBloomPass.js';
import { clampPixelRatio, pickQualityTier, prefersReducedMotion, webglAvailable } from './capabilities';
import { createVisualParams, stepVisualParams } from './statePresets';
import {
    innerFragmentShader,
    innerVertexShader,
    lineFragmentShader,
    lineVertexShader,
    orbFragmentShader,
    orbVertexShader,
    particleFragmentShader,
    particleVertexShader,
} from './shaders';
import { createVoiceVisualizationState } from './VoiceVisualizationState';

const TIER = {
    high: { detail: 3, particles: 420, curves: 48, curvePts: 96, bloom: true },
    medium: { detail: 2, particles: 240, curves: 32, curvePts: 72, bloom: false },
    low: { detail: 2, particles: 120, curves: 20, curvePts: 48, bloom: false },
};

function makeUniforms() {
    return {
        uTime: { value: 0 },
        uDeform: { value: 0.08 },
        uBreath: { value: 0.35 },
        uAmp: { value: 0 },
        uLow: { value: 0 },
        uMid: { value: 0 },
        uHigh: { value: 0 },
        uTight: { value: 0 },
        uReduced: { value: 0 },
        uGlow: { value: 0.28 },
        uOpacity: { value: 1 },
        uSaturate: { value: 1 },
        uWarning: { value: 0 },
        uSpin: { value: 0.18 },
        uSpeed: { value: 0.14 },
        uSize: { value: 1 },
        uTint: { value: new THREE.Vector3(1, 1, 1) },
    };
}

function flowingCurve(index, total, points) {
    const pts = [];
    const seed = index * 17.13;
    const tilt = ((index / total) * 2 - 1) * Math.PI;
    const phase = seed * 0.37;

    for (let i = 0; i <= points; i += 1) {
        const t = (i / points) * Math.PI * 2;
        const weave = 0.18 * Math.sin(t * 3.0 + phase) + 0.08 * Math.sin(t * 7.0 + seed);
        const r = 1.02 + weave;
        const lat = tilt * 0.55 + 0.35 * Math.sin(t * 2.0 + phase);
        const lon = t + index * 0.41;
        pts.push(new THREE.Vector3(
            r * Math.cos(lat) * Math.cos(lon),
            r * Math.sin(lat),
            r * Math.cos(lat) * Math.sin(lon),
        ));
    }

    return new THREE.CatmullRomCurve3(pts, true);
}

export class OrbEngine {
    constructor(container, options = {}) {
        this.container = container;
        this.disposed = false;
        this.raf = 0;
        this.last = performance.now();
        this.slowFrames = 0;
        this.tier = options.tier ?? pickQualityTier();
        this.visual = createVisualParams();
        this.viz = createVoiceVisualizationState();
        this.uniforms = makeUniforms();
        this.sourceRef = options.sourceRef ?? null;
        this.clock = 0;

        const spec = TIER[this.tier] ?? TIER.medium;
        const width = Math.max(1, container.clientWidth);
        const height = Math.max(1, container.clientHeight);

        this.renderer = new THREE.WebGLRenderer({
            antialias: this.tier !== 'low',
            alpha: true,
            powerPreference: 'high-performance',
        });
        this.renderer.setPixelRatio(clampPixelRatio(this.tier));
        this.renderer.setSize(width, height);
        this.renderer.setClearColor(0x000000, 0);
        this.renderer.outputColorSpace = THREE.SRGBColorSpace;
        container.appendChild(this.renderer.domElement);
        this.renderer.domElement.style.display = 'block';
        this.renderer.domElement.style.width = '100%';
        this.renderer.domElement.style.height = '100%';

        this.scene = new THREE.Scene();
        this.camera = new THREE.PerspectiveCamera(32, width / Math.max(1, height), 0.1, 20);
        this.camera.position.set(0, 0.08, 4.15);

        this.group = new THREE.Group();
        this.scene.add(this.group);

        this.orbMat = new THREE.ShaderMaterial({
            uniforms: this.uniforms,
            vertexShader: orbVertexShader,
            fragmentShader: orbFragmentShader,
            transparent: true,
            depthWrite: false,
            side: THREE.FrontSide,
        });
        this.orbGeo = new THREE.IcosahedronGeometry(1, spec.detail);
        this.orb = new THREE.Mesh(this.orbGeo, this.orbMat);
        this.group.add(this.orb);

        this.innerMat = new THREE.ShaderMaterial({
            uniforms: this.uniforms,
            vertexShader: innerVertexShader,
            fragmentShader: innerFragmentShader,
            transparent: true,
            depthWrite: false,
            blending: THREE.AdditiveBlending,
            side: THREE.DoubleSide,
        });
        this.innerGeo = new THREE.IcosahedronGeometry(0.74, Math.max(1, spec.detail - 1));
        this.inner = new THREE.Mesh(this.innerGeo, this.innerMat);
        this.group.add(this.inner);

        this.lineGeo = this.buildLines(spec);
        this.lineMat = new THREE.ShaderMaterial({
            uniforms: this.uniforms,
            vertexShader: lineVertexShader,
            fragmentShader: lineFragmentShader,
            transparent: true,
            depthWrite: false,
            blending: THREE.AdditiveBlending,
        });
        this.lines = new THREE.LineSegments(this.lineGeo, this.lineMat);
        this.group.add(this.lines);

        this.particleGeo = this.buildParticles(spec.particles);
        this.particleMat = new THREE.ShaderMaterial({
            uniforms: this.uniforms,
            vertexShader: particleVertexShader,
            fragmentShader: particleFragmentShader,
            transparent: true,
            depthWrite: false,
            blending: THREE.AdditiveBlending,
        });
        this.particles = new THREE.Points(this.particleGeo, this.particleMat);
        this.group.add(this.particles);

        this.useBloom = Boolean(spec.bloom && !prefersReducedMotion());
        if (this.useBloom) {
            this.composer = new EffectComposer(this.renderer);
            this.composer.addPass(new RenderPass(this.scene, this.camera));
            this.bloom = new UnrealBloomPass(new THREE.Vector2(width, height), 0.42, 0.6, 0.82);
            this.composer.addPass(this.bloom);
        }

        this.resizeObserver = new ResizeObserver(() => this.resize());
        this.resizeObserver.observe(container);
        this.loop = this.loop.bind(this);
        this.raf = requestAnimationFrame(this.loop);
    }

    buildLines(spec) {
        const positions = [];
        const offsets = [];

        for (let c = 0; c < spec.curves; c += 1) {
            const curve = flowingCurve(c, spec.curves, spec.curvePts);
            const pts = curve.getPoints(spec.curvePts);
            for (let i = 0; i < pts.length - 1; i += 1) {
                positions.push(pts[i].x, pts[i].y, pts[i].z);
                positions.push(pts[i + 1].x, pts[i + 1].y, pts[i + 1].z);
                offsets.push(i / pts.length + c * 0.017);
                offsets.push((i + 1) / pts.length + c * 0.017);
            }
        }

        const geo = new THREE.BufferGeometry();
        geo.setAttribute('position', new THREE.Float32BufferAttribute(positions, 3));
        geo.setAttribute('aOffset', new THREE.Float32BufferAttribute(offsets, 1));

        return geo;
    }

    buildParticles(count) {
        const pos = new Float32Array(count * 3);
        const seed = new Float32Array(count);

        for (let i = 0; i < count; i += 1) {
            const u = Math.random();
            const v = Math.random();
            const theta = 2 * Math.PI * u;
            const phi = Math.acos(2 * v - 1);
            const r = 1.12 + Math.random() * 0.42;
            pos[i * 3] = r * Math.sin(phi) * Math.cos(theta);
            pos[i * 3 + 1] = r * Math.cos(phi);
            pos[i * 3 + 2] = r * Math.sin(phi) * Math.sin(theta);
            seed[i] = Math.random();
        }

        const geo = new THREE.BufferGeometry();
        geo.setAttribute('position', new THREE.Float32BufferAttribute(pos, 3));
        geo.setAttribute('aSeed', new THREE.Float32BufferAttribute(seed, 1));

        return geo;
    }

    setVisualization(next) {
        this.viz = createVoiceVisualizationState(next);
    }

    resize() {
        if (this.disposed || ! this.container) {
            return;
        }

        const width = Math.max(1, this.container.clientWidth);
        const height = Math.max(1, this.container.clientHeight);
        this.camera.aspect = width / height;
        this.camera.updateProjectionMatrix();
        this.renderer.setPixelRatio(clampPixelRatio(this.tier));
        this.renderer.setSize(width, height, false);
        this.composer?.setSize(width, height);
        this.bloom?.setSize(width, height);
    }

    degrade() {
        if (this.tier === 'high') {
            this.tier = 'medium';
        } else if (this.tier === 'medium') {
            this.tier = 'low';
        } else {
            return;
        }

        this.useBloom = false;
        this.composer = null;
        this.bloom = null;
        this.renderer.setPixelRatio(clampPixelRatio(this.tier));
        this.particles.visible = this.tier !== 'low' ? this.particles.visible : true;
        if (this.tier === 'low') {
            this.uniforms.uSize.value = 0.7;
        }
    }

    loop(now) {
        if (this.disposed) {
            return;
        }

        const dt = Math.min(0.05, (now - this.last) / 1000);
        this.last = now;
        this.clock += dt * (this.viz.reducedMotion ? 0.35 : 1);

        if (this.sourceRef?.current) {
            this.setVisualization(this.sourceRef.current);
        }

        if (dt > 0.033) {
            this.slowFrames += 1;
            if (this.slowFrames > 45) {
                this.degrade();
                this.slowFrames = 0;
            }
        } else {
            this.slowFrames = Math.max(0, this.slowFrames - 1);
        }

        this.visual = stepVisualParams(this.visual, this.viz.state, dt);
        this.applyUniforms();
        this.animateCamera(dt);

        if (this.useBloom && this.composer) {
            this.composer.render();
        } else {
            this.renderer.render(this.scene, this.camera);
        }

        this.raf = requestAnimationFrame(this.loop);
    }

    applyUniforms() {
        const viz = this.viz;
        const v = this.visual;
        const bands = viz.frequencyBands || {};
        const source = v.audioSource;
        const liveAmp = source === 'output'
            ? viz.outputAmplitude
            : source === 'input'
                ? viz.inputAmplitude
                : 0;
        const amp = liveAmp * v.audioGain;
        const reduced = viz.reducedMotion ? 1 : 0;

        this.uniforms.uTime.value = this.clock;
        this.uniforms.uDeform.value = v.deform + amp * (source === 'none' ? 0 : 0.42);
        this.uniforms.uBreath.value = v.breath;
        this.uniforms.uAmp.value = amp;
        this.uniforms.uLow.value = (bands.sub || 0) * 0.55 + (bands.low || 0);
        this.uniforms.uMid.value = (bands.lowMid || 0) * 0.4 + (bands.mid || 0);
        this.uniforms.uHigh.value = (bands.highMid || 0) * 0.45 + (bands.high || 0);
        this.uniforms.uTight.value = v.tightness;
        this.uniforms.uReduced.value = reduced;
        this.uniforms.uGlow.value = v.glow * (viz.isMuted ? 0.55 : 1);
        this.uniforms.uOpacity.value = v.opacity;
        this.uniforms.uSaturate.value = v.saturate;
        this.uniforms.uWarning.value = v.warning;
        this.uniforms.uSpin.value = v.innerSpin;
        this.uniforms.uSpeed.value = v.lineSpeed;
        this.uniforms.uSize.value = this.tier === 'low' ? 0.7 : 1;

        const s = v.scale * (1 + amp * 0.04);
        this.group.scale.setScalar(s);
        this.group.rotation.y += v.lineSpeed * 0.0025 * (1 - reduced * 0.8);
        this.particles.rotation.y -= v.particleSpeed * 0.003 * (1 - reduced * 0.8);
        this.inner.rotation.z += v.innerSpin * 0.004 * (1 - reduced * 0.8);
    }

    animateCamera(dt) {
        if (this.viz.reducedMotion) {
            this.camera.position.set(0, 0.08, 4.15);
            this.camera.lookAt(0, 0, 0);

            return;
        }

        const t = this.clock;
        this.camera.position.x = Math.sin(t * 0.07) * 0.08;
        this.camera.position.y = 0.08 + Math.cos(t * 0.05) * 0.04;
        this.camera.lookAt(0, 0, 0);
        this.camera.position.z = 4.15;
        void dt;
    }

    dispose() {
        this.disposed = true;
        cancelAnimationFrame(this.raf);
        this.resizeObserver?.disconnect();
        this.orbGeo?.dispose();
        this.innerGeo?.dispose();
        this.lineGeo?.dispose();
        this.particleGeo?.dispose();
        this.orbMat?.dispose();
        this.innerMat?.dispose();
        this.lineMat?.dispose();
        this.particleMat?.dispose();
        this.composer = null;
        this.bloom = null;
        this.renderer?.dispose();
        this.renderer?.forceContextLoss?.();
        if (this.renderer?.domElement?.parentNode) {
            this.renderer.domElement.parentNode.removeChild(this.renderer.domElement);
        }
        this.scene = null;
        this.camera = null;
    }
}

export function canCreateOrbEngine() {
    return webglAvailable();
}
