export const SETTINGS_SECTIONS = [
    'profile',
    'assistant',
    'memory',
    'productivity',
    'voice',
    'integrations',
];

export function allowedSettingsSection(value) {
    const key = String(value || '').trim().toLowerCase();

    return SETTINGS_SECTIONS.includes(key) ? key : null;
}

export function writeSettingsQuery(section) {
    if (typeof window === 'undefined') {
        return;
    }

    const url = new URL(window.location.href);
    const allowed = allowedSettingsSection(section);

    if (allowed) {
        url.searchParams.set('settings', allowed);
    } else {
        url.searchParams.delete('settings');
    }

    window.history.replaceState({}, '', `${url.pathname}${url.search}${url.hash}`);
}
