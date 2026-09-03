import CabinetLayout from '@/Layouts/CabinetLayout';
import { Head, useForm, usePage } from '@inertiajs/react';
import { Save } from 'lucide-react';

export default function CabinetAiSettings() {
    const { generalPrompt = '', locale = 'en' } = usePage().props;
    const form = useForm({
        general_prompt: generalPrompt ?? '',
    });

    const text = {
        en: {
            title: 'AI Settings',
            subtitle: 'Edit your personal General Prompt. Provider, model, and platform prompts are managed by the owner.',
            prompt: 'General Prompt',
            save: 'Save',
        },
        ru: {
            title: 'AI Settings',
            subtitle: 'Edit your personal General Prompt. Provider, model, and platform prompts are managed by the owner.',
            prompt: 'General Prompt',
            save: 'Save',
        },
        uk: {
            title: 'AI Settings',
            subtitle: 'Edit your personal General Prompt. Provider, model, and platform prompts are managed by the owner.',
            prompt: 'General Prompt',
            save: 'Save',
        },
    };
    const t = text[locale] ?? text.en;

    return (
        <CabinetLayout title={t.title}>
            <Head title={t.title} />

            <div className="app-widget space-y-4 p-6">
                <p className="text-sm text-slate-600">{t.subtitle}</p>
                <form
                    className="space-y-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.patch(route('cabinet.ai-settings.update'), { preserveScroll: true });
                    }}
                >
                    <div>
                        <label className="mb-1 block text-sm font-medium text-slate-700">{t.prompt}</label>
                        <textarea
                            rows={10}
                            value={form.data.general_prompt}
                            onChange={(e) => form.setData('general_prompt', e.target.value)}
                            className="block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-100"
                        />
                        {form.errors.general_prompt && (
                            <p className="mt-2 text-sm text-red-600">{form.errors.general_prompt}</p>
                        )}
                    </div>
                    <button
                        type="submit"
                        disabled={form.processing}
                        className="inline-flex h-10 items-center gap-2 rounded-lg bg-indigo-600 px-4 text-sm font-semibold text-white transition hover:bg-indigo-700 disabled:opacity-60"
                    >
                        <Save className="h-4 w-4" />
                        {t.save}
                    </button>
                </form>
            </div>
        </CabinetLayout>
    );
}
