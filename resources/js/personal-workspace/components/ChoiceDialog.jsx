import { useEffect } from 'react';

/**
 * A short list of alternatives — used where a menu would need a submenu.
 *
 * @param {{key: string, label: string}[]} options
 */
export default function ChoiceDialog({ open, title, options = [], footer = null, onSelect, onCancel }) {
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
        <div className="fixed inset-0 z-[60] flex items-center justify-center bg-black/60 p-4" onClick={onCancel}>
            <div
                role="dialog"
                aria-modal="true"
                aria-label={title}
                className="w-full max-w-xs rounded-2xl border border-white/10 bg-[#10182a] p-4 shadow-2xl"
                onClick={(event) => event.stopPropagation()}
            >
                <p className="text-sm font-semibold text-white">{title}</p>
                <div className="mt-3 space-y-1">
                    {options.map((option) => (
                        <button
                            key={option.key}
                            type="button"
                            onClick={() => onSelect?.(option.key)}
                            className="block w-full rounded-lg px-3 py-2 text-left text-xs text-slate-200 hover:bg-white/10"
                        >
                            {option.label}
                        </button>
                    ))}
                </div>
                {footer ? <div className="mt-3 border-t border-white/10 pt-3">{footer}</div> : null}
                <button
                    type="button"
                    onClick={onCancel}
                    className="mt-3 w-full rounded-lg px-3 py-1.5 text-xs text-slate-400 hover:text-white"
                >
                    Вернуться
                </button>
            </div>
        </div>
    );
}
