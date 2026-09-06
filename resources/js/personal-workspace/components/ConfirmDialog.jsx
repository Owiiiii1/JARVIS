import { useEffect } from 'react';

/**
 * Replaces window.confirm so a decision looks like part of the product and can say what it means.
 */
export default function ConfirmDialog({
    open,
    title,
    body = null,
    confirmLabel = 'Продолжить',
    cancelLabel = 'Вернуться',
    tone = 'default',
    busy = false,
    onConfirm,
    onCancel,
}) {
    useEffect(() => {
        if (!open) {
            return undefined;
        }

        const onKeyDown = (event) => {
            if (event.key === 'Escape') {
                event.stopPropagation();
                onCancel?.();
            }
        };

        document.addEventListener('keydown', onKeyDown, true);

        return () => document.removeEventListener('keydown', onKeyDown, true);
    }, [open, onCancel]);

    if (!open) {
        return null;
    }

    return (
        <div
            className="fixed inset-0 z-[60] flex items-center justify-center bg-black/60 p-4"
            onClick={onCancel}
        >
            <div
                role="dialog"
                aria-modal="true"
                aria-label={title}
                className="w-full max-w-md rounded-2xl border border-white/10 bg-[#10182a] p-5 shadow-2xl"
                onClick={(event) => event.stopPropagation()}
            >
                <p className="text-sm font-semibold text-white">{title}</p>
                {body ? <div className="mt-2 text-xs leading-relaxed text-slate-300">{body}</div> : null}
                <div className="mt-4 flex justify-end gap-2">
                    <button
                        type="button"
                        onClick={onCancel}
                        className="rounded-lg px-3 py-1.5 text-xs text-slate-300 hover:text-white"
                    >
                        {cancelLabel}
                    </button>
                    <button
                        type="button"
                        disabled={busy}
                        onClick={onConfirm}
                        className={`rounded-lg px-3 py-1.5 text-xs font-medium text-white disabled:opacity-50 ${
                            tone === 'danger' ? 'bg-rose-500/90 hover:bg-rose-400' : 'bg-sky-500/90 hover:bg-sky-400'
                        }`}
                    >
                        {confirmLabel}
                    </button>
                </div>
            </div>
        </div>
    );
}
