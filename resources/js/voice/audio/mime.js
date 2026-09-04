export const DEFAULT_RECORDER_CANDIDATES = [
    'audio/webm;codecs=opus',
    'audio/webm',
    'audio/ogg;codecs=opus',
    'audio/ogg',
    'audio/mp4',
];

const ALIASES = {
    'audio/x-wav': 'audio/wav',
    'audio/wave': 'audio/wav',
    'audio/mpeg': 'audio/mpeg',
    'audio/mp3': 'audio/mpeg',
    'audio/x-m4a': 'audio/mp4',
    'audio/m4a': 'audio/mp4',
    'audio/x-aac': 'audio/aac',
};

export function canonicalizeMime(mime) {
    const base = String(mime || '').split(';')[0].trim().toLowerCase();

    if (! base) {
        return '';
    }

    return ALIASES[base] ?? base;
}

export function parseAudioMime(mime) {
    const raw = String(mime || '').trim();
    const canonical = canonicalizeMime(raw);
    const extension = extensionForMime(canonical);

    return {
        raw: raw || canonical,
        canonical,
        extension,
        filename: `utterance.${extension}`,
    };
}

export function extensionForMime(mime) {
    switch (canonicalizeMime(mime)) {
        case 'audio/mpeg':
            return 'mp3';
        case 'audio/wav':
            return 'wav';
        case 'audio/ogg':
            return 'ogg';
        case 'audio/mp4':
        case 'audio/m4a':
            return 'm4a';
        case 'audio/flac':
            return 'flac';
        case 'audio/aac':
            return 'aac';
        default:
            return 'webm';
    }
}

export function pickRecorderMime(candidates = DEFAULT_RECORDER_CANDIDATES) {
    if (typeof MediaRecorder === 'undefined') {
        return parseAudioMime('audio/webm');
    }

    const list = Array.isArray(candidates) && candidates.length ? candidates : DEFAULT_RECORDER_CANDIDATES;
    const match = list.find((type) => {
        try {
            return MediaRecorder.isTypeSupported(type);
        } catch {
            return false;
        }
    });

    if (match) {
        return parseAudioMime(match);
    }

    return parseAudioMime('audio/webm');
}

export function describeBlobMime(blob, fallbackMime) {
    return parseAudioMime(blob?.type || fallbackMime || 'audio/webm');
}
