import CabinetLayout from '@/Layouts/CabinetLayout';
import { Head, usePage } from '@inertiajs/react';

export default function CabinetIndex() {
    const { user = {} } = usePage().props;

    return (
        <CabinetLayout title="Jarvis Cabinet">
            <Head title="Jarvis Cabinet" />

            <div className="app-widget space-y-4 p-6">
                <p className="text-sm text-slate-600">
                    Welcome to your personal Jarvis workspace. Chat and profile features will arrive in a later milestone.
                </p>

                <dl className="grid gap-4 sm:grid-cols-2">
                    <InfoRow label="Name" value={user.name} />
                    <InfoRow label="Email" value={user.email} />
                    <InfoRow label="Role" value={user.role} />
                    <InfoRow label="Status" value={user.status} />
                    <InfoRow label="Timezone" value={user.timezone} />
                </dl>
            </div>
        </CabinetLayout>
    );
}

function InfoRow({ label, value }) {
    return (
        <div className="rounded-lg border border-slate-200 bg-white px-4 py-3">
            <dt className="text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</dt>
            <dd className="mt-1 text-sm font-medium text-slate-900">{value ?? '—'}</dd>
        </div>
    );
}
