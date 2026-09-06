export default function ConversationDeleteDialog({
    conversation,
    deleting = false,
    onCancel,
    onConfirm,
}) {
    if (!conversation) {
        return null;
    }

    const title = String(conversation.title || '').trim() || 'Без названия';

    return (
        <div className="fixed inset-0 z-[60] flex items-center justify-center bg-black/60 p-4" onClick={onCancel}>
            <div
                className="w-full max-w-md rounded-2xl border border-white/10 bg-[#10182a] p-5 shadow-2xl"
                onClick={(event) => event.stopPropagation()}
                role="dialog"
                aria-modal="true"
                aria-labelledby="delete-chat-title"
            >
                <h2 id="delete-chat-title" className="text-base font-semibold text-white">
                    Удалить этот чат?
                </h2>
                <p className="mt-2 truncate text-sm font-medium text-slate-200">«{title}»</p>
                <p className="mt-3 text-sm leading-5 text-slate-400">
                    История сообщений этого разговора будет удалена.
                    Это действие нельзя отменить.
                </p>
                <div className="mt-5 flex justify-end gap-2">
                    <button
                        type="button"
                        onClick={onCancel}
                        disabled={deleting}
                        className="rounded-lg px-3 py-2 text-sm text-slate-400 hover:text-white disabled:opacity-60"
                    >
                        Отмена
                    </button>
                    <button
                        type="button"
                        onClick={onConfirm}
                        disabled={deleting}
                        className="rounded-lg bg-rose-500 px-3 py-2 text-sm font-medium text-white hover:bg-rose-400 disabled:opacity-60"
                    >
                        {deleting ? 'Удаление...' : 'Удалить'}
                    </button>
                </div>
            </div>
        </div>
    );
}
