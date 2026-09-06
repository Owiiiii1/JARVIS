import { MoreHorizontal } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

/**
 * Secondary actions live here so a card can show one obvious primary action.
 *
 * @param {{label: string, onSelect: Function, tone?: 'default'|'danger', disabled?: boolean}[]} items
 */
export default function OverflowMenu({ items = [], label = 'Ещё действия', align = 'right' }) {
    const [open, setOpen] = useState(false);
    const containerRef = useRef(null);
    const buttonRef = useRef(null);
    const available = items.filter(Boolean);

    useEffect(() => {
        if (!open) {
            return undefined;
        }

        const onPointerDown = (event) => {
            if (!containerRef.current?.contains(event.target)) {
                setOpen(false);
            }
        };

        const onKeyDown = (event) => {
            if (event.key === 'Escape') {
                event.stopPropagation();
                setOpen(false);
                buttonRef.current?.focus();
            }
        };

        document.addEventListener('mousedown', onPointerDown);
        document.addEventListener('keydown', onKeyDown, true);

        return () => {
            document.removeEventListener('mousedown', onPointerDown);
            document.removeEventListener('keydown', onKeyDown, true);
        };
    }, [open]);

    if (available.length === 0) {
        return null;
    }

    return (
        <div className="relative" ref={containerRef}>
            <button
                ref={buttonRef}
                type="button"
                aria-haspopup="menu"
                aria-expanded={open}
                aria-label={label}
                onClick={() => setOpen((value) => !value)}
                className="flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 hover:bg-white/10 hover:text-white"
            >
                <MoreHorizontal className="h-4 w-4" />
            </button>
            {open ? (
                <div
                    role="menu"
                    className={`absolute z-20 mt-1 min-w-44 overflow-hidden rounded-xl border border-white/10 bg-[#121a2b] py-1 shadow-2xl ${align === 'right' ? 'right-0' : 'left-0'}`}
                >
                    {available.map((item) => (
                        <button
                            key={item.label}
                            type="button"
                            role="menuitem"
                            disabled={item.disabled}
                            onClick={() => {
                                setOpen(false);
                                item.onSelect?.();
                            }}
                            className={`block w-full px-3 py-2 text-left text-xs disabled:opacity-40 ${
                                item.tone === 'danger'
                                    ? 'text-rose-300 hover:bg-rose-500/10'
                                    : 'text-slate-200 hover:bg-white/10'
                            }`}
                        >
                            {item.label}
                        </button>
                    ))}
                </div>
            ) : null}
        </div>
    );
}
