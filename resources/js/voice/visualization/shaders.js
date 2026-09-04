export const orbVertexShader = /* glsl */ `
uniform float uTime;
uniform float uDeform;
uniform float uBreath;
uniform float uAmp;
uniform float uLow;
uniform float uMid;
uniform float uHigh;
uniform float uTight;
uniform float uReduced;

varying vec3 vNormal;
varying vec3 vView;
varying vec3 vWorld;
varying float vNoise;

float hash(vec3 p) {
    p = fract(p * 0.3183099 + vec3(0.11, 0.17, 0.13));
    p *= 17.0;
    return fract(p.x * p.y * p.z * (p.x + p.y + p.z));
}

float valueNoise(vec3 x) {
    vec3 i = floor(x);
    vec3 f = fract(x);
    f = f * f * (3.0 - 2.0 * f);
    float n000 = hash(i);
    float n100 = hash(i + vec3(1.0, 0.0, 0.0));
    float n010 = hash(i + vec3(0.0, 1.0, 0.0));
    float n110 = hash(i + vec3(1.0, 1.0, 0.0));
    float n001 = hash(i + vec3(0.0, 0.0, 1.0));
    float n101 = hash(i + vec3(1.0, 0.0, 1.0));
    float n011 = hash(i + vec3(0.0, 1.0, 1.0));
    float n111 = hash(i + vec3(1.0, 1.0, 1.0));
    float nx00 = mix(n000, n100, f.x);
    float nx10 = mix(n010, n110, f.x);
    float nx01 = mix(n001, n101, f.x);
    float nx11 = mix(n011, n111, f.x);
    float nxy0 = mix(nx00, nx10, f.y);
    float nxy1 = mix(nx01, nx11, f.y);
    return mix(nxy0, nxy1, f.z);
}

float fbm(vec3 p) {
    float v = 0.0;
    float a = 0.5;
    for (int i = 0; i < 4; i++) {
        v += a * valueNoise(p);
        p = p * 2.07 + vec3(0.17, 0.31, 0.13);
        a *= 0.5;
    }
    return v;
}

void main() {
    vec3 n = normalize(normal);
    float t = uTime;
    float reduced = 1.0 - uReduced * 0.85;
    float breath = (0.018 + uBreath * 0.028) * sin(t * (0.7 + uBreath)) * reduced;
    float lowPulse = uLow * 0.11 * reduced;
    float n1 = fbm(position * (1.6 + uTight * 0.8) + vec3(t * 0.17, t * 0.11, t * 0.09));
    float n2 = valueNoise(position * (4.2 + uHigh * 3.0) + vec3(t * 0.41, t * -0.27, t * 0.33));
    float displace = (n1 * 0.72 + n2 * 0.28 - 0.42);
    displace *= (uDeform + uAmp * 0.55 + uMid * 0.18) * reduced;
    vec3 pos = position + n * (displace * 0.22 + breath + lowPulse);
    vNoise = n1;
    vec4 world = modelMatrix * vec4(pos, 1.0);
    vWorld = world.xyz;
    vNormal = normalize(mat3(modelMatrix) * n);
    vec4 mv = viewMatrix * world;
    vView = -mv.xyz;
    gl_Position = projectionMatrix * mv;
}
`;

export const orbFragmentShader = /* glsl */ `
uniform float uTime;
uniform float uGlow;
uniform float uOpacity;
uniform float uSaturate;
uniform float uWarning;
uniform float uAmp;
uniform vec3 uTint;

varying vec3 vNormal;
varying vec3 vView;
varying vec3 vWorld;
varying float vNoise;

void main() {
    vec3 n = normalize(vNormal);
    vec3 v = normalize(vView);
    float fresnel = pow(1.0 - abs(dot(n, v)), 2.4);
    float inner = pow(abs(dot(n, v)), 1.6);
    vec3 cool = vec3(0.22, 0.62, 0.78);
    vec3 steel = vec3(0.55, 0.72, 0.82);
    vec3 core = mix(cool, steel, clamp(vNoise, 0.0, 1.0));
    core = mix(vec3(dot(core, vec3(0.3, 0.5, 0.2))), core, uSaturate);
    vec3 warn = vec3(0.72, 0.48, 0.22);
    core = mix(core, warn, uWarning * 0.55);
    core *= uTint;
    float glow = uGlow * (0.35 + fresnel * 1.15 + uAmp * 0.25);
    vec3 color = core * (0.22 + inner * 0.45) + vec3(0.72, 0.9, 1.0) * glow;
    float alpha = uOpacity * (0.18 + fresnel * 0.72 + inner * 0.12);
    gl_FragColor = vec4(color, clamp(alpha, 0.0, 0.92));
}
`;

export const innerVertexShader = /* glsl */ `
uniform float uTime;
uniform float uSpin;
uniform float uReduced;
varying vec3 vPos;
void main() {
    float t = uTime * (0.25 + uSpin) * (1.0 - uReduced * 0.7);
    float c = cos(t);
    float s = sin(t * 0.7);
    vec3 p = position;
    p.xz = mat2(c, -s, s, c) * p.xz;
    vPos = p;
    gl_Position = projectionMatrix * modelViewMatrix * vec4(p * 0.72, 1.0);
}
`;

export const innerFragmentShader = /* glsl */ `
uniform float uTime;
uniform float uGlow;
uniform float uWarning;
uniform float uOpacity;
varying vec3 vPos;
float hash(vec2 p) {
    return fract(sin(dot(p, vec2(127.1, 311.7))) * 43758.5453);
}
void main() {
    float n = hash(vPos.xy * 3.1 + uTime * 0.15);
    float bands = 0.5 + 0.5 * sin(vPos.y * 18.0 + uTime * (1.4 + uGlow));
    vec3 c = mix(vec3(0.12, 0.42, 0.58), vec3(0.7, 0.9, 1.0), bands);
    c = mix(c, vec3(0.7, 0.42, 0.18), uWarning * 0.4);
    float a = uOpacity * (0.12 + n * 0.18 + bands * 0.22) * uGlow;
    gl_FragColor = vec4(c, clamp(a, 0.0, 0.55));
}
`;

export const lineVertexShader = /* glsl */ `
uniform float uTime;
uniform float uSpeed;
uniform float uAmp;
attribute float aOffset;
varying float vAlong;
void main() {
    vAlong = fract(aOffset + uTime * uSpeed * 0.15);
    vec3 p = position * (1.0 + uAmp * 0.04);
    gl_Position = projectionMatrix * modelViewMatrix * vec4(p, 1.0);
}
`;

export const lineFragmentShader = /* glsl */ `
uniform float uGlow;
uniform float uOpacity;
uniform float uWarning;
uniform float uSaturate;
varying float vAlong;
void main() {
    float dash = smoothstep(0.0, 0.12, vAlong) * (1.0 - smoothstep(0.55, 0.9, vAlong));
    vec3 c = mix(vec3(0.35, 0.78, 0.92), vec3(0.85, 0.95, 1.0), dash);
    c = mix(vec3(dot(c, vec3(0.33))), c, uSaturate);
    c = mix(c, vec3(0.85, 0.55, 0.25), uWarning * 0.5);
    gl_FragColor = vec4(c, uOpacity * dash * (0.25 + uGlow * 0.7));
}
`;

export const particleVertexShader = /* glsl */ `
uniform float uTime;
uniform float uSpeed;
uniform float uSize;
uniform float uReduced;
attribute float aSeed;
varying float vAlpha;
void main() {
    float t = uTime * uSpeed * (1.0 - uReduced * 0.75);
    float ang = aSeed * 6.28318 + t * 0.35;
    float lift = sin(t * 0.8 + aSeed * 12.0) * 0.08;
    vec3 p = position;
    p.xz = mat2(cos(ang * 0.15), -sin(ang * 0.15), sin(ang * 0.15), cos(ang * 0.15)) * p.xz;
    p.y += lift;
    vec4 mv = modelViewMatrix * vec4(p, 1.0);
    gl_Position = projectionMatrix * mv;
    gl_PointSize = uSize * (1.4 - uReduced * 0.5) * (140.0 / -mv.z);
    vAlpha = 0.25 + 0.55 * fract(aSeed + t * 0.1);
}
`;

export const particleFragmentShader = /* glsl */ `
uniform float uOpacity;
uniform float uWarning;
varying float vAlpha;
void main() {
    vec2 uv = gl_PointCoord * 2.0 - 1.0;
    float d = dot(uv, uv);
    if (d > 1.0) discard;
    vec3 c = mix(vec3(0.55, 0.85, 1.0), vec3(0.9, 0.6, 0.3), uWarning);
    gl_FragColor = vec4(c, uOpacity * vAlpha * (1.0 - d));
}
`;
