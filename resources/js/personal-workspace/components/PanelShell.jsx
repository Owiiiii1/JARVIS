import { Loader2 } from 'lucide-react';
import { useEffect } from 'react';

/**
 * The frame every workspace panel shares: backdrop, sliding sheet, header, scrolling body.
 */
export default function PanelShell({
    icon: Icon,
    iconClassName = 'text-sky-300',
    title,
    onClose,
    toolbar = null,
    loading = false,
    error = '',
    children,
}) {
    useEffect(() => {
        const onKeyDown = (event) => {
            if (event.key === 'Escape') {
                onClose?.();
            }
        };

        document.addEventListener('keydown', onKeyDown);

        return () => document.removeEventListener('keydown', onKeyDown);
    }, [onClose]);

    return (
        <div className="fixed inset-0 z-50 flex justify-end bg-black/50" onClick={onClose}>
            <aside
                role="dialog"
                aria-modal="true"
                aria-label={title}
                className="flex h-full w-full max-w-md flex-col border-l border-white/10 bg-slate-950 text-slate-100 shadow-2xl"
                onClick={(event) => event.stopPropagation()}
            >
                <div className="flex items-center justify-between border-b border-white/10 px-4 py-3">
                    <div className="flex items-center gap-2">
                        {Icon ? <Icon className={`h-4 w-4 ${iconClassName}`} /> : null}
                        <h2 className="text-sm font-semibold">{title}</h2>
                    </div>
                    <button type="button" onClick={onClose} className="rounded-lg px-2 py-1 text-xs text-slate-400 hover:text-white">
                        Закрыть
                    </button>
                </div>
                {toolbar ? <div className="border-b border-white/5 px-4 py-3">{toolbar}</div> : null}
                <div className="min-h-0 flex-1 space-y-5 overflow-y-auto px-4 py-4">
                    {error ? <p className="text-xs text-rose-300">{error}</p> : null}
                    {loading ? (
                        <p className="flex items-center gap-2 text-sm text-slate-400">
                            <Loader2 className="h-4 w-4 animate-spin" />
                            Загрузка…
                        </p>
                    ) : (
                        children
                    )}
                </div>
            </aside>
        </div>
    );
}
