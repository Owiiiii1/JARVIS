import { workspaceRoute } from '@/personal-workspace/named';
import { Link } from '@inertiajs/react';
import { MoreVertical } from 'lucide-react';

function formatWhen(iso, timezone) {
    if (!iso) {
        return '';
    }

    try {
        return new Date(iso).toLocaleString(undefined, {
            timeZone: timezone || undefined,
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    } catch {
        return iso;
    }
}

export default function ConversationSidebarItem({
    item,
    active,
    surface,
    timezone,
    menuOpen,
    renaming,
    renameDraft,
    onToggleMenu,
    onRenameDraft,
    onSaveRename,
    onCancelRename,
    onStartRename,
    onRequestDelete,
    onNavigate,
}) {
    const title = String(item.title || '').trim() || 'Без названия';

    return (
        <li className="relative">
            <div
                className={`flex items-start rounded-xl transition ${
                    active
                        ? 'bg-white/10 text-white ring-1 ring-sky-400/30'
                        : 'text-slate-300 hover:bg-white/5'
                }`}
            >
                {renaming ? (
                    <form
                        className="min-w-0 flex-1 px-3 py-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            onSaveRename();
                        }}
                    >
                        <input
                            autoFocus
                            value={renameDraft}
                            maxLength={120}
                            onChange={(event) => onRenameDraft(event.target.value)}
                            onBlur={onSaveRename}
                            onKeyDown={(event) => {
                                if (event.key === 'Escape') {
                                    event.preventDefault();
                                    onCancelRename();
                                }
                            }}
                            className="w-full rounded-md border border-white/15 bg-black/40 px-2 py-1 text-sm text-white outline-none focus:border-sky-400/40"
                            aria-label="Название чата"
                        />
                    </form>
                ) : (
                    <Link
                        href={workspaceRoute(surface, 'chats.show', item.id)}
                        onClick={onNavigate}
                        className="min-w-0 flex-1 px-3 py-2"
                    >
                        <span className="block truncate text-sm font-medium">{title}</span>
                        {item.last_activity_at ? (
                            <span className="mt-0.5 block text-[11px] text-slate-500">
                                {formatWhen(item.last_activity_at, timezone)}
                            </span>
                        ) : null}
                    </Link>
                )}
                <button
                    type="button"
                    className="mt-1 mr-1 shrink-0 rounded-lg p-1.5 text-slate-400 hover:bg-white/10 hover:text-white"
                    aria-label={`Действия с чатом ${title}`}
                    aria-expanded={menuOpen}
                    aria-haspopup="menu"
                    onClick={(event) => {
                        event.preventDefault();
                        event.stopPropagation();
                        onToggleMenu();
                    }}
                >
                    <MoreVertical className="h-4 w-4" />
                </button>
            </div>
            {menuOpen ? (
                <div
                    className="absolute right-2 top-10 z-40 min-w-[10rem] rounded-xl border border-white/10 bg-[#10182a] p-1 shadow-2xl"
                    role="menu"
                    onClick={(event) => event.stopPropagation()}
                >
                    <button
                        type="button"
                        role="menuitem"
                        className="block w-full rounded-lg px-3 py-2 text-left text-sm text-slate-200 hover:bg-white/5"
                        onClick={() => {
                            onStartRename();
                        }}
                    >
                        Переименовать
                    </button>
                    <button
                        type="button"
                        role="menuitem"
                        className="block w-full rounded-lg px-3 py-2 text-left text-sm text-rose-300 hover:bg-rose-500/10"
                        onClick={() => {
                            onRequestDelete();
                        }}
                    >
                        Удалить
                    </button>
                </div>
            ) : null}
        </li>
    );
}
