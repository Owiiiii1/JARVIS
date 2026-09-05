import { getSharedAudioContext, resumeSharedAudioContext } from '@/voice/audio/voiceMedia';
import { canonicalizeMime, describeBlobMime } from '@/voice/audio/mime';

const TARGET_RATE = 16000;

export function encodePcm16Wav(samples, sampleRate) {
    const dataSize = samples.length * 2;
    const buffer = new ArrayBuffer(44 + dataSize);
    const view = new DataView(buffer);

    writeAscii(view, 0, 'RIFF');
    view.setUint32(4, 36 + dataSize, true);
    writeAscii(view, 8, 'WAVE');
    writeAscii(view, 12, 'fmt ');
    view.setUint32(16, 16, true);
    view.setUint16(20, 1, true);
    view.setUint16(22, 1, true);
    view.setUint32(24, sampleRate, true);
    view.setUint32(28, sampleRate * 2, true);
    view.setUint16(32, 2, true);
    view.setUint16(34, 16, true);
    writeAscii(view, 36, 'data');
    view.setUint32(40, dataSize, true);

    let offset = 44;
    for (let i = 0; i < samples.length; i += 1) {
        const clipped = Math.max(-1, Math.min(1, samples[i]));
        view.setInt16(offset, clipped < 0 ? clipped * 0x8000 : clipped * 0x7fff, true);
        offset += 2;
    }

    return new Blob([buffer], { type: 'audio/wav' });
}

export async function transcodeBlobToWav(blob, sampleRate = TARGET_RATE) {
    await resumeSharedAudioContext();
    const ctx = getSharedAudioContext();
    if (! ctx) {
        throw new Error('voice_audio_format_unsupported');
    }

    const source = await blob.arrayBuffer();
    const decoded = await ctx.decodeAudioData(source.slice(0));
    const mono = mixToMono(decoded);
    const samples = resampleLinear(mono, decoded.sampleRate, sampleRate);

    if (samples.length < sampleRate * 0.08) {
        throw new Error('voice_audio_format_unsupported');
    }

    return encodePcm16Wav(samples, sampleRate);
}

export async function prepareUtteranceBlob(blob, rawMime, sttProvider) {
    const parsed = describeBlobMime(blob, rawMime);
    const provider = String(sttProvider || '').toLowerCase();

    if (parsed.canonical === 'audio/wav') {
        return {
            blob,
            mime: 'audio/wav',
            rawMime: 'audio/wav',
        };
    }

    try {
        const wav = await transcodeBlobToWav(blob);

        return {
            blob: wav,
            mime: 'audio/wav',
            rawMime: 'audio/wav',
        };
    } catch (error) {
        if (provider === 'gemini' || provider === '') {
            throw error;
        }

        return {
            blob,
            mime: parsed.canonical || 'audio/webm',
            rawMime: parsed.raw || rawMime || parsed.canonical,
        };
    }
}

export function needsGeminiWav(mime, sttProvider) {
    return String(sttProvider || '').toLowerCase() === 'gemini'
        && canonicalizeMime(mime) !== 'audio/wav';
}

function mixToMono(buffer) {
    const channels = buffer.numberOfChannels;
    const length = buffer.length;

    if (channels === 1) {
        return buffer.getChannelData(0);
    }

    const mixed = new Float32Array(length);
    for (let channel = 0; channel < channels; channel += 1) {
        const data = buffer.getChannelData(channel);
        for (let i = 0; i < length; i += 1) {
            mixed[i] += data[i] / channels;
        }
    }

    return mixed;
}

function resampleLinear(input, fromRate, toRate) {
    if (! fromRate || fromRate === toRate) {
        return input;
    }

    const ratio = fromRate / toRate;
    const length = Math.max(1, Math.round(input.length / ratio));
    const output = new Float32Array(length);

    for (let i = 0; i < length; i += 1) {
        const position = i * ratio;
        const index = Math.floor(position);
        const next = Math.min(index + 1, input.length - 1);
        const mix = position - index;
        output[i] = (input[index] * (1 - mix)) + (input[next] * mix);
    }

    return output;
}

function writeAscii(view, offset, value) {
    for (let i = 0; i < value.length; i += 1) {
        view.setUint8(offset + i, value.charCodeAt(i));
    }
}
