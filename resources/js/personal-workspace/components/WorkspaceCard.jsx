import OverflowMenu from '@/personal-workspace/components/OverflowMenu';

/**
 * One card grammar for tasks, reminders and watchers.
 *
 * Title says what it is, the secondary line says when or in what state it is, problems get their
 * own red line, and everything else hides behind the overflow menu.
 */
export default function WorkspaceCard({
    title,
    secondary = null,
    secondaryTone = 'muted',
    meta = [],
    problem = null,
    badge = null,
    muted = false,
    primaryAction = null,
    actions = [],
    children = null,
    footer = null,
}) {
    const metaLine = meta.filter(Boolean);

    return (
        <li className={`rounded-xl border border-white/10 bg-black/20 px-3 py-2 ${muted ? 'opacity-60' : ''}`}>
            <div className="flex items-start gap-2">
                <div className="min-w-0 flex-1">
                    <p className={`text-sm ${muted ? 'text-slate-400 line-through' : 'text-slate-100'}`}>{title}</p>
                    {secondary ? (
                        <p className={`mt-0.5 text-xs ${secondaryTone === 'alert' ? 'text-rose-300' : 'text-slate-400'}`}>
                            {secondary}
                        </p>
                    ) : null}
                    {metaLine.length > 0 ? (
                        <p className="mt-1 text-[11px] text-slate-500">{metaLine.join(' · ')}</p>
                    ) : null}
                    {problem ? <p className="mt-1 text-[11px] text-amber-300">{problem}</p> : null}
                    {badge ? (
                        <span className="mt-1 inline-block rounded-md bg-white/10 px-1.5 py-0.5 text-[10px] uppercase tracking-wide text-slate-300">
                            {badge}
                        </span>
                    ) : null}
                </div>
                <div className="flex shrink-0 items-center gap-1">
                    {primaryAction ? (
                        <button
                            type="button"
                            disabled={primaryAction.disabled}
                            onClick={primaryAction.onSelect}
                            className="rounded-lg border border-emerald-400/30 bg-emerald-500/10 px-2.5 py-1 text-xs text-emerald-200 hover:bg-emerald-500/20 disabled:opacity-40"
                        >
                            {primaryAction.label}
                        </button>
                    ) : null}
                    <OverflowMenu items={actions} />
                </div>
            </div>
            {children}
            {footer}
        </li>
    );
}
