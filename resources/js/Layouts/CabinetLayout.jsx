import { Link, router, usePage } from '@inertiajs/react';
import { LogOut, Sparkles, UserCircle2 } from 'lucide-react';

export default function CabinetLayout({ title, children }) {
    const { auth, owlAdmin = {} } = usePage().props;
    const user = auth?.user;
    const brandName = owlAdmin?.brand_name ?? 'Jarvis';
    const logoPath = owlAdmin?.logo_path ?? '/images/company-logo.svg';

    return (
        <div className="min-h-screen bg-[#F4EFE4] text-slate-900">
            <header className="border-b border-slate-200/80 bg-white/90 backdrop-blur">
                <div className="mx-auto flex max-w-5xl items-center justify-between gap-4 px-4 py-4 sm:px-6">
                    <div className="flex items-center gap-3">
                        <img src={logoPath} alt={brandName} className="h-10 w-10 rounded-xl shadow-sm" />
                        <div>
                            <p className="text-lg font-semibold text-slate-900">{brandName} Cabinet</p>
                            <p className="text-xs uppercase tracking-[0.18em] text-slate-500">Personal workspace</p>
                        </div>
                    </div>

                    <div className="flex items-center gap-3">
                        <nav className="hidden items-center gap-2 text-sm sm:flex">
                            <Link
                                href={route('cabinet.index')}
                                className="rounded-lg px-3 py-2 font-medium text-slate-700 hover:bg-slate-100"
                            >
                                Cabinet
                            </Link>
                            <Link
                                href={route('cabinet.ai-settings.edit')}
                                className="inline-flex items-center gap-1 rounded-lg px-3 py-2 font-medium text-slate-700 hover:bg-slate-100"
                            >
                                <Sparkles className="h-4 w-4" />
                                AI Settings
                            </Link>
                        </nav>
                        <div className="hidden items-center gap-2 text-sm text-slate-600 sm:flex">
                            <UserCircle2 className="h-4 w-4" />
                            <span>{user?.name ?? 'User'}</span>
                        </div>
                        <button
                            type="button"
                            onClick={() => router.post(route('logout'))}
                            className="inline-flex h-10 items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 text-sm font-medium text-slate-700 transition hover:bg-slate-50"
                        >
                            <LogOut className="h-4 w-4" />
                            Log out
                        </button>
                    </div>
                </div>
            </header>

            <main className="mx-auto max-w-5xl px-4 py-8 sm:px-6">
                {title ? <h1 className="mb-6 text-2xl font-semibold text-slate-900">{title}</h1> : null}
                {children}
            </main>

            <footer className="border-t border-slate-200/80 bg-white/70">
                <div className="mx-auto max-w-5xl px-4 py-4 text-center text-xs text-slate-500 sm:px-6">
                    Powered by{' '}
                    <Link href="https://owlsolutions.net" className="font-medium text-slate-700 hover:underline">
                        OwlSolutions
                    </Link>
                </div>
            </footer>
        </div>
    );
}
