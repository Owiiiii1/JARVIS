import { ArrowLeft, X } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import AssistantSettings from '@/personal-workspace/settings/AssistantSettings';
import IntegrationsSettings from '@/personal-workspace/settings/IntegrationsSettings';
import MemorySettings from '@/personal-workspace/settings/MemorySettings';
import ProductivitySettings from '@/personal-workspace/settings/ProductivitySettings';
import ProfileSettings from '@/personal-workspace/settings/ProfileSettings';
import SettingsNavigation from '@/personal-workspace/settings/SettingsNavigation';
import VoiceSettings from '@/personal-workspace/settings/VoiceSettings';
import { allowedSettingsSection } from '@/personal-workspace/settings/sections';

function visibleSettingsSections(capabilities) {
    const sections = [
        { id: 'profile', label: 'Profile', description: 'Имя, пояс, знакомство' },
        { id: 'assistant', label: 'Assistant', description: 'Личность и General Prompt' },
    ];

    if (capabilities.memory) {
        sections.push({ id: 'memory', label: 'Memory', description: 'Запомненные факты и темы' });
    }

    if (capabilities.tasks || capabilities.reminders || capabilities.notifications) {
        sections.push({ id: 'productivity', label: 'Productivity', description: 'Сводки, Push, подсказки' });
    }

    if (capabilities.voice) {
        sections.push({ id: 'voice', label: 'Voice', description: 'Голос ассистента' });
    }

    if (capabilities.integrations || capabilities.telegramDm) {
        sections.push({ id: 'integrations', label: 'Integrations', description: 'Каналы и подключения' });
    }

    return sections;
}

export default function WorkspaceSettings({
    open,
    section,
    onSectionChange,
    onClose,
    surface,
    user,
    settings,
    capabilities,
    assistantProfile,
    settingsContext,
    showOnboarding,
    onboardingLabel,
    onboardingStatus,
}) {
    const sections = useMemo(() => visibleSettingsSections(capabilities), [capabilities]);
    const current = allowedSettingsSection(section) && sections.some((item) => item.id === section)
        ? section
        : (sections[0]?.id || 'profile');
    const [mobileDetail, setMobileDetail] = useState(Boolean(allowedSettingsSection(section)));

    useEffect(() => {
        if (!open) {
            setMobileDetail(false);
        }
    }, [open]);

    if (!open) {
        return null;
    }

    const selectSection = (id) => {
        onSectionChange(id);
        setMobileDetail(true);
    };

    const body = (() => {
        if (current === 'assistant') {
            return (
                <AssistantSettings
                    surface={surface}
                    settings={settings}
                    assistantProfile={assistantProfile}
                    generalPrompt={settingsContext.general_prompt ?? settings.general_prompt}
                />
            );
        }

        if (current === 'memory') {
            return <MemorySettings memory={settingsContext.memory} capabilities={capabilities} />;
        }

        if (current === 'productivity') {
            return <ProductivitySettings surface={surface} settings={settings} capabilities={capabilities} />;
        }

        if (current === 'voice') {
            return <VoiceSettings surface={surface} user={user} settings={settings} />;
        }

        if (current === 'integrations') {
            return (
                <IntegrationsSettings
                    integrations={settingsContext.integrations || []}
                    telegram={settingsContext.telegram}
                    capabilities={capabilities}
                />
            );
        }

        return (
            <ProfileSettings
                surface={surface}
                user={user}
                settings={settings}
                assistantProfile={assistantProfile}
                capabilities={capabilities}
                showOnboarding={showOnboarding}
                onboardingLabel={onboardingLabel}
                onboardingStatus={onboardingStatus}
                onClose={onClose}
            />
        );
    })();

    return (
        <div className="fixed inset-0 z-50 flex items-start justify-end bg-black/50 p-3 sm:p-6" onClick={onClose}>
            <div
                className="flex h-full max-h-[92vh] w-full max-w-4xl flex-col overflow-hidden rounded-2xl border border-white/10 bg-[#101826] shadow-2xl"
                onClick={(event) => event.stopPropagation()}
                role="dialog"
                aria-modal="true"
                aria-label="Настройки"
            >
                <div className="flex items-center justify-between border-b border-white/10 px-4 py-3">
                    <div className="flex items-center gap-2 text-white">
                        <button
                            type="button"
                            className={`rounded-lg p-1 text-slate-400 hover:text-white md:hidden ${mobileDetail ? '' : 'invisible'}`}
                            onClick={() => setMobileDetail(false)}
                            aria-label="Назад к списку разделов"
                        >
                            <ArrowLeft className="h-4 w-4" />
                        </button>
                        <h2 className="text-sm font-semibold">Настройки</h2>
                    </div>
                    <button type="button" onClick={onClose} className="rounded-lg p-1 text-slate-400 hover:text-white" aria-label="Закрыть настройки">
                        <X className="h-4 w-4" />
                    </button>
                </div>
                <div className="flex min-h-0 flex-1">
                    <div className={`${mobileDetail ? 'hidden' : 'flex'} w-full shrink-0 flex-col border-white/10 p-3 md:flex md:w-56 md:border-r`}>
                        <SettingsNavigation sections={sections} current={current} onSelect={selectSection} />
                    </div>
                    <div className={`${mobileDetail ? 'flex' : 'hidden'} min-h-0 min-w-0 flex-1 flex-col md:flex`}>
                        <div className="min-h-0 flex-1 overflow-y-auto px-4 py-4">
                            {body}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}
