import { router, usePage } from '@inertiajs/react';

export default function ImpersonationBanner() {
    const { impersonation } = usePage().props;
    if (! impersonation?.active) {
        return null;
    }

    const name = impersonation.user_name || 'User';

    return (
        <div className="sticky top-0 z-[80] flex items-center justify-between gap-3 bg-amber-500 px-4 py-2 text-sm font-medium text-slate-950 shadow">
            <p>
                Viewing as <span className="font-semibold">{name}</span>. Changes can modify this user’s data.
            </p>
            <button
                type="button"
                onClick={() => router.post(route('impersonation.stop'))}
                className="rounded-md bg-slate-950 px-3 py-1 text-xs font-semibold text-white hover:bg-slate-800"
            >
                Exit impersonation
            </button>
        </div>
    );
}
