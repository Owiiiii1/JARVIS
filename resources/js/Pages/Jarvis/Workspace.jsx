import SafeMarkdown from '@/Components/Jarvis/SafeMarkdown';
import JarvisWorkspaceLayout from '@/Layouts/JarvisWorkspaceLayout';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import {
    Bell,
    Check,
    FileText,
    FolderKanban,
    HardDrive,
    Loader2,
    Menu,
    MessageSquarePlus,
    Mic,
    PanelRight,
    Pencil,
    Plug,
    Paperclip,
    Search,
    Send,
    Settings2,
    Sparkles,
    Type,
    UserCircle2,
    X,
} from 'lucide-react';
import { lazy, Suspense, useEffect, useMemo, useRef, useState } from 'react';

const SUGGESTIONS = [
    'Что у меня сегодня?',
    'Проверь календарь',
    'Посмотри новые письма',
    'Что изменилось в JARVIS?',
    'Напомни...',
];

const VoiceSession = lazy(() => import('@/Components/Jarvis/VoiceSession'));

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

function newClientId() {
    if (typeof crypto !== 'undefined' && crypto.randomUUID) {
        return crypto.randomUUID();
    }

    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (char) => {
        const rand = (Math.random() * 16) | 0;
        const value = char === 'x' ? rand : (rand & 0x3) | 0x8;

        return value.toString(16);
    });
}

const IMAGE_MIME_ALIASES = {
    'image/jpg': 'image/jpeg',
    'image/pjpeg': 'image/jpeg',
    'image/jfif': 'image/jpeg',
    'image/jpe': 'image/jpeg',
    'image/x-jpeg': 'image/jpeg',
};

function normalizeImageMime(type) {
    const raw = String(type || '').toLowerCase().trim();

    return IMAGE_MIME_ALIASES[raw] || raw;
}

const BLOCKED_CLIENT_MIMES = [
    'image/svg+xml',
    'text/html',
    'text/xml',
    'application/xml',
    'application/pdf',
    'application/javascript',
    'application/x-php',
    'application/x-executable',
    'application/zip',
];

function isDesktopPointer() {
    return typeof window !== 'undefined' && window.matchMedia('(hover: hover) and (pointer: fine)').matches;
}

function focusComposer(textarea, { forceDesktopOnly = true } = {}) {
    if (!textarea || textarea.disabled) {
        return;
    }

    if (forceDesktopOnly && !isDesktopPointer()) {
        return;
    }

    textarea.focus({ preventScroll: true });
}

function formatBytes(bytes) {
    const value = Number(bytes || 0);

    if (value < 1024) {
        return `${value} B`;
    }

    if (value < 1024 * 1024) {
        return `${(value / 1024).toFixed(1)} KB`;
    }

    return `${(value / (1024 * 1024)).toFixed(1)} MB`;
}

function fileExtension(name) {
    const parts = String(name || '').toLowerCase().split('.');

    return parts.length > 1 ? parts.pop() : '';
}
function isAllowedClientImage(file) {
    const mime = normalizeImageMime(file?.type);

    if (BLOCKED_CLIENT_MIMES.includes(mime) || mime.includes('svg')) {
        return false;
    }

    return Boolean(file) && (mime.startsWith('image/') || mime === '');
}

function isStorageTextFile(file, allowedExtensions = []) {
    const ext = fileExtension(file?.name);

    if (ext && allowedExtensions.includes(ext)) {
        return true;
    }

    const mime = String(file?.type || '').toLowerCase();

    return mime.startsWith('text/') || mime === 'application/json' || mime === 'application/xml';
}

function withStatus(message, status = 'completed') {
    return {
        ...message,
        status: message.status ?? status,
    };
}

function draftKey(conversationId) {
    return `jarvis.draft.${conversationId}`;
}

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

function providerLabel(toolName) {
    if (!toolName) {
        return 'Action';
    }
    if (toolName.includes('gmail')) {
        return 'Gmail';
    }
    if (toolName.includes('calendar')) {
        return 'Calendar';
    }
    if (toolName.includes('github')) {
        return 'GitHub';
    }
    return toolName.replaceAll('_', ' ');
}

function integrationDot(state) {
    if (state === 'connected' || state === 'enabled') {
        return 'bg-emerald-400';
    }
    if (state === 'error' || state === 'revoked') {
        return 'bg-rose-400';
    }
    if (state === 'permission_required' || state === 'incomplete') {
        return 'bg-amber-400';
    }
    return 'bg-slate-500';
}

export default function JarvisWorkspace() {
    const {
        conversation,
        conversations = [],
        messages: initialMessages = [],
        hasMore: initialHasMore = false,
        oldestId: initialOldestId = null,
        user = {},
        context = {},
        owlAdmin = {},
        flash = {},
        chatAttachments = null,
        jarvisStorage = null,
    } = usePage().props;

    const timezone = user.timezone || undefined;
    const imageAccept = chatAttachments?.accept
        ? `${chatAttachments.accept},image/*`
        : 'image/*,.jpg,.jpeg,.png,.webp';
    const storageAccept = jarvisStorage?.accept || '';
    const acceptTypes = [imageAccept, storageAccept].filter(Boolean).join(',');
    const maxImages = Number(chatAttachments?.max_images_per_message || 0);
    const maxFileBytes = Number(chatAttachments?.max_file_size_mb || 0) * 1024 * 1024;
    const maxTotalBytes = Number(chatAttachments?.max_total_upload_mb || 0) * 1024 * 1024;
    const maxStorageFiles = Number(jarvisStorage?.max_files_per_upload || 8);
    const maxStorageBytes = Number(jarvisStorage?.max_file_size_mb || 20) * 1024 * 1024;
    const storageExtensions = jarvisStorage?.allowed_extensions || [];
    const allowedMimes = chatAttachments?.allowed_mime_types || [];
    const retentionHours = Number(chatAttachments?.retention_hours || 24);
    const brandName = owlAdmin?.brand_name ?? 'Jarvis';
    const [mode, setMode] = useState('text');
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const [contextCollapsed, setContextCollapsed] = useState(false);
    const [contextDrawer, setContextDrawer] = useState(false);
    const [query, setQuery] = useState('');
    const [messages, setMessages] = useState(() => initialMessages.map((item) => withStatus(item)));
    const [hasMore, setHasMore] = useState(initialHasMore);
    const [oldestId, setOldestId] = useState(initialOldestId);
    const [draft, setDraft] = useState('');
    const [pendingFiles, setPendingFiles] = useState([]);
    const [lightbox, setLightbox] = useState(null);
    const [sending, setSending] = useState(false);
    const [loadingOlder, setLoadingOlder] = useState(false);
    const [error, setError] = useState('');
    const [editingTitle, setEditingTitle] = useState(false);
    const [titleDraft, setTitleDraft] = useState(conversation?.title ?? '');
    const [promptOpen, setPromptOpen] = useState(false);
    const [settingsOpen, setSettingsOpen] = useState(false);
    const scrollerRef = useRef(null);
    const fileInputRef = useRef(null);
    const composerRef = useRef(null);
    const shouldStickToBottom = useRef(true);
    const pendingFilesRef = useRef([]);

    const promptForm = useForm({
        general_prompt: context?.settings?.general_prompt ?? '',
    });

    useEffect(() => {
        setMessages(initialMessages.map((item) => withStatus(item)));
        setHasMore(initialHasMore);
        setOldestId(initialOldestId);
        setTitleDraft(conversation?.title ?? '');
        setError('');
        setSidebarOpen(false);
        setPendingFiles((current) => {
            current.forEach((item) => {
                if (item.url) {
                    URL.revokeObjectURL(item.url);
                }
            });

            return [];
        });
        shouldStickToBottom.current = true;

        try {
            setDraft(window.localStorage.getItem(draftKey(conversation?.id)) ?? '');
        } catch {
            setDraft('');
        }
    }, [conversation?.id, initialHasMore, initialMessages, initialOldestId, conversation?.title]);

    useEffect(() => {
        promptForm.setData('general_prompt', context?.settings?.general_prompt ?? '');
    }, [context?.settings?.general_prompt]);

    useEffect(() => {
        if (!conversation?.id) {
            return;
        }

        try {
            window.localStorage.setItem(draftKey(conversation.id), draft);
        } catch {
            // ignore quota / private mode
        }
    }, [conversation?.id, draft]);

    useEffect(() => {
        pendingFilesRef.current = pendingFiles;
    }, [pendingFiles]);

    useEffect(() => {
        return () => {
            pendingFilesRef.current.forEach((item) => {
                if (item.url) {
                    URL.revokeObjectURL(item.url);
                }
            });
        };
    }, []);

    useEffect(() => {
        if (!sending) {
            focusComposer(composerRef.current, { forceDesktopOnly: true });
        }
    }, [sending]);

    useEffect(() => {
        if (!shouldStickToBottom.current || !scrollerRef.current) {
            return;
        }

        scrollerRef.current.scrollTop = scrollerRef.current.scrollHeight;
    }, [messages, sending]);

    const filteredConversations = useMemo(() => {
        const needle = query.trim().toLowerCase();

        if (!needle) {
            return conversations;
        }

        return conversations.filter((item) => String(item.title ?? '').toLowerCase().includes(needle));
    }, [conversations, query]);

    const applyTurnPayload = (payload, optimisticId, clientMessageId) => {
        setMessages((current) => {
            const withoutOptimistic = current.filter((item) => item.id !== optimisticId);
            const next = [...withoutOptimistic];

            if (payload.inbound && !next.some((item) => Number(item.id) === Number(payload.inbound.id))) {
                next.push(withStatus(payload.inbound, 'completed'));
            }

            if (payload.assistant && !next.some((item) => Number(item.id) === Number(payload.assistant.id))) {
                next.push(withStatus(payload.assistant, 'completed'));
            }

            if (payload.error) {
                next.push({
                    id: `error-${clientMessageId}`,
                    kind: 'error',
                    role: 'system',
                    channel: 'web',
                    body: payload.error,
                    occurred_at: new Date().toISOString(),
                    status: 'failed',
                });
            }

            return next;
        });

        if (payload.error) {
            setError(payload.error);
        }
    };

    const firstError = (payload) => {
        const errors = payload?.errors;

        if (errors && typeof errors === 'object') {
            for (const value of Object.values(errors)) {
                if (Array.isArray(value) && value[0]) {
                    return String(value[0]);
                }

                if (typeof value === 'string' && value !== '') {
                    return value;
                }
            }
        }

        return payload?.message || payload?.error || 'Не удалось отправить сообщение.';
    };

    const addPendingFiles = (fileList) => {
        const incoming = Array.from(fileList || []).filter(Boolean);

        if (incoming.length === 0) {
            return;
        }

        setPendingFiles((current) => {
            const next = [...current];
            let message = '';

            incoming.forEach((file) => {
                const asStorage = isStorageTextFile(file, storageExtensions) && !String(file.type || '').startsWith('image/');

                if (asStorage) {
                    if (next.filter((item) => item.kind === 'file').length >= maxStorageFiles) {
                        message = `Можно прикрепить не больше ${maxStorageFiles} файлов.`;
                        return;
                    }

                    if (file.size > maxStorageBytes) {
                        message = `Файл больше ${jarvisStorage.max_file_size_mb} МБ.`;
                        return;
                    }

                    next.push({
                        id: `local-${newClientId()}`,
                        kind: 'file',
                        file,
                        name: file.name || 'file',
                        mime: file.type,
                        size: file.size,
                    });

                    return;
                }

                if (next.filter((item) => item.kind !== 'file').length >= maxImages) {
                    message = `Можно прикрепить не больше ${maxImages} изображений.`;
                    return;
                }

                if (!isAllowedClientImage(file)) {
                    message = 'Нужны изображения PNG/JPEG/WebP или текстовый файл для Storage.';
                    return;
                }

                if (file.size > maxFileBytes) {
                    message = `Изображение больше ${chatAttachments.max_file_size_mb} МБ.`;
                    return;
                }

                const total = next.filter((item) => item.kind !== 'file').reduce((sum, item) => sum + item.file.size, 0) + file.size;

                if (total > maxTotalBytes) {
                    message = `Суммарный размер изображений больше ${chatAttachments.max_total_upload_mb} МБ.`;
                    return;
                }

                next.push({
                    id: `local-${newClientId()}`,
                    kind: 'image',
                    file,
                    url: URL.createObjectURL(file),
                    name: file.name || 'image',
                    mime: normalizeImageMime(file.type) || file.type,
                    size: file.size,
                });
            });

            if (message) {
                setError(message);
            }

            return next;
        });
    };

    const removePendingFile = (id) => {
        setPendingFiles((current) => {
            const selected = current.find((item) => item.id === id);

            if (selected?.url) {
                URL.revokeObjectURL(selected.url);
            }

            return current.filter((item) => item.id !== id);
        });
        focusComposer(composerRef.current, { forceDesktopOnly: true });
    };

    const handleClipboardPaste = (event) => {
        const items = Array.from(event.clipboardData?.items || []);
        const imageFiles = items
            .filter((item) => allowedMimes.includes(normalizeImageMime(item.type)))
            .map((item) => item.getAsFile())
            .filter(Boolean);

        if (imageFiles.length === 0) {
            return;
        }

        addPendingFiles(imageFiles);

        const text = event.clipboardData?.getData('text/plain') || '';

        if (text === '') {
            event.preventDefault();
        }
    };

    const handleDrop = (event) => {
        event.preventDefault();
        addPendingFiles(event.dataTransfer?.files);
    };

    const sendBody = async (body) => {
        const text = body.trim();
        const files = pendingFiles.map((item) => item.file);

        if (sending || (!text && files.length === 0)) {
            return;
        }

        const clientMessageId = newClientId();
        const optimistic = {
            id: `tmp-${clientMessageId}`,
            kind: 'user',
            role: 'user',
            channel: 'web',
            body: text,
            occurred_at: new Date().toISOString(),
            pending: true,
            status: 'pending',
            attachments: pendingFiles
                .filter((item) => item.kind !== 'file')
                .map((item) => ({
                    id: item.id,
                    kind: 'image',
                    mime_type: item.mime,
                    size_bytes: item.size,
                    preview_url: item.url,
                    view_url: item.url,
                    retention_class: 'ephemeral',
                })),
            stored_files: pendingFiles
                .filter((item) => item.kind === 'file')
                .map((item) => ({
                    public_id: item.id,
                    display_name: item.name,
                    size_bytes: item.size,
                    status: 'uploaded',
                })),
        };

        shouldStickToBottom.current = true;
        setDraft('');
        setSending(true);
        setError('');
        setMessages((current) => [...current, optimistic]);

        const form = new FormData();
        form.append('body', text);
        form.append('client_message_id', clientMessageId);
        pendingFiles.forEach((item) => {
            if (item.kind === 'file') {
                form.append('files[]', item.file);
            } else {
                form.append('images[]', item.file);
            }
        });

        try {
            const response = await fetch(route('jarvis.messages.store', conversation.id), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: form,
            });

            const payload = await response.json().catch(() => ({}));

            if (!response.ok) {
                throw new Error(firstError(payload));
            }

            pendingFiles.forEach((item) => {
                if (item.url) {
                    URL.revokeObjectURL(item.url);
                }
            });
            setPendingFiles([]);
            applyTurnPayload(payload, optimistic.id, clientMessageId);
        } catch (caught) {
            setError(caught.message || 'Не удалось получить ответ от Jarvis. Попробуйте ещё раз позже.');
            setMessages((current) => [
                ...current.filter((item) => item.id !== optimistic.id),
                {
                    id: `failed-${clientMessageId}`,
                    kind: 'error',
                    role: 'system',
                    channel: 'web',
                    body: caught.message || 'Request failed.',
                    occurred_at: new Date().toISOString(),
                    status: 'failed',
                },
            ]);
            setDraft(text);
        } finally {
            setSending(false);
        }
    };

    const resolveConfirmation = async (confirmationId, confirm) => {
        if (sending || !confirmationId) {
            return;
        }

        const clientMessageId = newClientId();
        setSending(true);
        setError('');
        shouldStickToBottom.current = true;

        try {
            const url = route(
                confirm ? 'jarvis.confirmations.confirm' : 'jarvis.confirmations.cancel',
                confirmationId,
            );
            const response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ client_message_id: clientMessageId }),
            });
            const payload = await response.json().catch(() => ({}));

            if (!response.ok) {
                throw new Error(payload.message || 'Confirmation could not be processed.');
            }

            applyTurnPayload(payload, null, clientMessageId);
        } catch (caught) {
            setError(caught.message || 'Confirmation failed.');
        } finally {
            setSending(false);
        }
    };

    const loadOlder = async () => {
        if (!hasMore || loadingOlder || !oldestId) {
            return;
        }

        setLoadingOlder(true);
        shouldStickToBottom.current = false;
        const scroller = scrollerRef.current;
        const previousHeight = scroller?.scrollHeight ?? 0;

        try {
            const url = new URL(route('jarvis.messages.older', conversation.id), window.location.origin);
            url.searchParams.set('before_id', String(oldestId));

            const response = await fetch(url.toString(), {
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            const payload = await response.json();
            const incoming = (payload.messages ?? []).map((item) => withStatus(item));

            setMessages((current) => {
                const existing = new Set(current.map((item) => Number(item.id)));
                const prepended = incoming.filter((item) => !existing.has(Number(item.id)));

                return [...prepended, ...current];
            });
            setHasMore(Boolean(payload.has_more));
            setOldestId(payload.oldest_id ?? oldestId);

            requestAnimationFrame(() => {
                if (scroller) {
                    scroller.scrollTop = scroller.scrollHeight - previousHeight;
                }
            });
        } finally {
            setLoadingOlder(false);
        }
    };

    const saveTitle = () => {
        const title = titleDraft.trim();

        if (!title || title === conversation.title) {
            setEditingTitle(false);
            setTitleDraft(conversation.title);

            return;
        }

        router.patch(
            route('jarvis.chats.update', conversation.id),
            { title },
            {
                preserveScroll: true,
                onFinish: () => setEditingTitle(false),
            },
        );
    };

    const empty = messages.length === 0;
    const projects = context.projects ?? [];
    const reminders = context.reminders ?? [];
    const integrations = context.integrations ?? [];
    const memory = context.memory ?? {};
    const connected = Boolean(owlAdmin?.ai?.connected);

    const closeOverlaysFromBackdrop = (event) => {
        if (event.target?.dataset?.sidebarBackdrop) {
            setSidebarOpen(false);
        }
        if (event.target?.dataset?.contextBackdrop) {
            setContextDrawer(false);
        }
    };

    const header = (
        <header className="flex h-14 shrink-0 items-center justify-between gap-3 border-b border-white/10 bg-black/20 px-3 sm:px-4">
            <div className="flex min-w-0 items-center gap-2">
                <button
                    type="button"
                    className="rounded-lg p-2 text-slate-300 hover:bg-white/10 lg:hidden"
                    onClick={() => setSidebarOpen(true)}
                    aria-label="Open conversations"
                >
                    <Menu className="h-4 w-4" />
                </button>
                <div className="min-w-0">
                    <div className="flex items-center gap-2">
                        <span className="text-sm font-semibold tracking-wide text-white">{brandName}</span>
                        <span
                            className={`h-2 w-2 rounded-full ${connected ? 'bg-emerald-400' : 'bg-rose-400'}`}
                            title={connected ? 'AI connected' : 'AI not connected'}
                            aria-label={connected ? 'AI connected' : 'AI not connected'}
                        />
                    </div>
                    {editingTitle ? (
                        <input
                            autoFocus
                            value={titleDraft}
                            maxLength={120}
                            onChange={(event) => setTitleDraft(event.target.value)}
                            onBlur={saveTitle}
                            onKeyDown={(event) => {
                                if (event.key === 'Enter') {
                                    event.preventDefault();
                                    saveTitle();
                                }
                                if (event.key === 'Escape') {
                                    setEditingTitle(false);
                                    setTitleDraft(conversation.title);
                                }
                            }}
                            className="mt-0.5 h-7 w-full max-w-md rounded-md border border-white/15 bg-black/30 px-2 text-xs text-white"
                            aria-label="Conversation title"
                        />
                    ) : (
                        <button
                            type="button"
                            onClick={() => setEditingTitle(true)}
                            className="mt-0.5 flex max-w-[46vw] items-center gap-1 truncate text-xs text-slate-400 hover:text-slate-200"
                            aria-label="Rename conversation"
                        >
                            <span className="truncate">{conversation?.title}</span>
                            <Pencil className="h-3 w-3 shrink-0" />
                        </button>
                    )}
                </div>
            </div>

            <div className="flex items-center gap-1 sm:gap-2">
                <div className="inline-flex rounded-full border border-white/10 bg-black/30 p-0.5" role="tablist" aria-label="Mode">
                    <button
                        type="button"
                        role="tab"
                        aria-selected={mode === 'text'}
                        onClick={() => setMode('text')}
                        className={`inline-flex items-center gap-1 rounded-full px-3 py-1.5 text-xs font-medium ${
                            mode === 'text' ? 'bg-white/10 text-white' : 'text-slate-400 hover:text-white'
                        }`}
                    >
                        <Type className="h-3.5 w-3.5" />
                        Text
                    </button>
                    <button
                        type="button"
                        role="tab"
                        aria-selected={mode === 'voice'}
                        onClick={() => setMode('voice')}
                        className={`inline-flex items-center gap-1 rounded-full px-3 py-1.5 text-xs font-medium ${
                            mode === 'voice' ? 'bg-white/10 text-white' : 'text-slate-400 hover:text-white'
                        }`}
                    >
                        <Mic className="h-3.5 w-3.5" />
                        Voice
                    </button>
                </div>
                <Link
                    href={route('dashboard')}
                    className="hidden rounded-lg px-3 py-1.5 text-xs font-medium text-slate-400 hover:bg-white/10 hover:text-white sm:inline-flex"
                >
                    Admin
                </Link>
                <button
                    type="button"
                    onClick={() => setSettingsOpen(true)}
                    className="rounded-lg p-2 text-slate-300 hover:bg-white/10"
                    aria-label="Workspace settings"
                >
                    <Settings2 className="h-4 w-4" />
                </button>
                <button
                    type="button"
                    onClick={() => {
                        if (window.matchMedia('(min-width: 1280px)').matches) {
                            setContextCollapsed((open) => !open);
                        } else {
                            setContextDrawer((open) => !open);
                        }
                    }}
                    className="rounded-lg p-2 text-slate-300 hover:bg-white/10"
                    aria-label="Toggle context panel"
                    aria-pressed={!contextCollapsed || contextDrawer}
                >
                    <PanelRight className="h-4 w-4" />
                </button>
            </div>
        </header>
    );

    const sidebar = (
        <div className="flex h-full min-h-0 flex-col">
            <div className="flex items-center justify-between px-4 py-4">
                <div>
                    <p className="text-sm font-semibold text-white">{brandName}</p>
                    <p className="text-[10px] uppercase tracking-[0.18em] text-slate-500">Workspace</p>
                </div>
                <button
                    type="button"
                    className="rounded-lg p-2 text-slate-400 hover:bg-white/10 lg:hidden"
                    onClick={() => setSidebarOpen(false)}
                    aria-label="Close conversations"
                >
                    <X className="h-4 w-4" />
                </button>
            </div>
            <div className="px-3">
                <button
                    type="button"
                    onClick={() => router.post(route('jarvis.chats.store'))}
                    className="inline-flex h-10 w-full items-center justify-center gap-2 rounded-xl bg-sky-500/90 px-3 text-sm font-semibold text-white hover:bg-sky-400"
                >
                    <MessageSquarePlus className="h-4 w-4" />
                    New Chat
                </button>
                <Link
                    href={route('jarvis.storage.index')}
                    onClick={() => setSidebarOpen(false)}
                    className="mt-2 inline-flex h-10 w-full items-center justify-center gap-2 rounded-xl border border-white/10 px-3 text-sm font-semibold text-slate-200 hover:bg-white/5"
                >
                    <HardDrive className="h-4 w-4" />
                    Storage
                </Link>
                <label className="mt-3 flex items-center gap-2 rounded-xl border border-white/10 bg-black/20 px-3 py-2">
                    <Search className="h-3.5 w-3.5 text-slate-500" />
                    <input
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder="Search chats"
                        className="w-full bg-transparent text-sm text-slate-200 outline-none placeholder:text-slate-600"
                        aria-label="Search conversations"
                    />
                </label>
            </div>
            <nav className="mt-3 min-h-0 flex-1 overflow-y-auto px-2 pb-3">
                {filteredConversations.length === 0 ? (
                    <p className="px-3 py-6 text-sm text-slate-500">No chats.</p>
                ) : (
                    <ul className="space-y-1">
                        {filteredConversations.map((item) => {
                            const active = Number(item.id) === Number(conversation?.id);

                            return (
                                <li key={item.id}>
                                    <Link
                                        href={route('jarvis.chats.show', item.id)}
                                        onClick={() => setSidebarOpen(false)}
                                        className={`block rounded-xl px-3 py-2 transition ${
                                            active
                                                ? 'bg-white/10 text-white ring-1 ring-sky-400/30'
                                                : 'text-slate-300 hover:bg-white/5'
                                        }`}
                                    >
                                        <span className="block truncate text-sm font-medium">{item.title}</span>
                                        {item.last_activity_at ? (
                                            <span className="mt-0.5 block text-[11px] text-slate-500">
                                                {formatWhen(item.last_activity_at, timezone)}
                                            </span>
                                        ) : null}
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </nav>
        </div>
    );

    const contextPanel = (
        <div className="flex h-full min-h-0 flex-col">
            <div className="flex items-center justify-between px-4 py-4">
                <p className="text-sm font-semibold text-white">Context</p>
                <button
                    type="button"
                    className="rounded-lg p-2 text-slate-400 hover:bg-white/10 xl:hidden"
                    onClick={() => setContextDrawer(false)}
                    aria-label="Close context"
                >
                    <X className="h-4 w-4" />
                </button>
            </div>
            <div className="min-h-0 flex-1 space-y-4 overflow-y-auto px-3 pb-4">
                <section className="rounded-2xl border border-white/10 bg-white/5 p-3">
                    <div className="mb-2 flex items-center justify-between">
                        <h2 className="flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.14em] text-slate-400">
                            <FolderKanban className="h-3.5 w-3.5" />
                            Projects
                        </h2>
                        <Link href={route('projects.index')} className="text-[11px] text-sky-300 hover:text-sky-200">
                            Admin
                        </Link>
                    </div>
                    {projects.length === 0 ? (
                        <p className="text-xs text-slate-500">No active projects.</p>
                    ) : (
                        <ul className="space-y-1.5">
                            {projects.map((project) => (
                                <li key={project.id}>
                                    <Link
                                        href={route('projects.show', project.id)}
                                        className="flex items-center justify-between rounded-lg px-2 py-1.5 text-sm text-slate-200 hover:bg-white/5"
                                    >
                                        <span className="truncate">{project.name}</span>
                                        {project.attached ? (
                                            <span className="ml-2 shrink-0 rounded-full bg-sky-400/15 px-2 py-0.5 text-[10px] text-sky-200">
                                                attached
                                            </span>
                                        ) : null}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section className="rounded-2xl border border-white/10 bg-white/5 p-3">
                    <h2 className="mb-2 flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.14em] text-slate-400">
                        <Bell className="h-3.5 w-3.5" />
                        Reminders
                    </h2>
                    {reminders.length === 0 ? (
                        <p className="text-xs text-slate-500">Ask Jarvis to remind you.</p>
                    ) : (
                        <ul className="space-y-2">
                            {reminders.map((reminder) => (
                                <li key={reminder.id} className="rounded-lg bg-black/20 px-2 py-2">
                                    <p className="line-clamp-2 text-sm text-slate-200">{reminder.text}</p>
                                    <p className="mt-1 text-[11px] text-slate-500">
                                        {formatWhen(reminder.run_at, reminder.timezone || timezone)} · {reminder.status}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section className="rounded-2xl border border-white/10 bg-white/5 p-3">
                    <div className="mb-2 flex items-center justify-between">
                        <h2 className="flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.14em] text-slate-400">
                            <Plug className="h-3.5 w-3.5" />
                            Integrations
                        </h2>
                        <Link
                            href={route('settings.index', { tab: 'integrations' })}
                            className="text-[11px] text-sky-300 hover:text-sky-200"
                        >
                            Admin
                        </Link>
                    </div>
                    <ul className="space-y-2">
                        {integrations.map((item) => (
                            <li key={item.provider} className="rounded-lg bg-black/20 px-2 py-2">
                                <div className="flex items-center justify-between gap-2">
                                    <span className="text-sm text-slate-200">{item.display_name}</span>
                                    <span className={`h-2 w-2 rounded-full ${integrationDot(item.state)}`} />
                                </div>
                                <p className="mt-0.5 truncate text-[11px] text-slate-500">
                                    {item.account_label || item.label}
                                </p>
                                {item.capabilities?.length ? (
                                    <p className="mt-1 text-[11px] text-slate-400">
                                        {item.capabilities
                                            .map((capability) => `${capability.label}: ${capability.state}`)
                                            .join(' · ')}
                                    </p>
                                ) : null}
                            </li>
                        ))}
                    </ul>
                </section>

                <section className="rounded-2xl border border-white/10 bg-white/5 p-3">
                    <h2 className="mb-2 flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.14em] text-slate-400">
                        <UserCircle2 className="h-3.5 w-3.5" />
                        Memory
                    </h2>
                    <dl className="grid grid-cols-2 gap-2 text-xs">
                        <div className="rounded-lg bg-black/20 px-2 py-2">
                            <dt className="text-slate-500">Facts</dt>
                            <dd className="mt-0.5 text-sm text-white">{memory.facts_count ?? 0}</dd>
                        </div>
                        <div className="rounded-lg bg-black/20 px-2 py-2">
                            <dt className="text-slate-500">Topics</dt>
                            <dd className="mt-0.5 text-sm text-white">{memory.topics_count ?? 0}</dd>
                        </div>
                    </dl>
                    <p className="mt-2 text-[11px] text-slate-500">
                        Last analysis: {memory.last_analysis_at ? formatWhen(memory.last_analysis_at, timezone) : '—'}
                    </p>
                    <button
                        type="button"
                        onClick={() => setPromptOpen(true)}
                        className="mt-3 inline-flex h-9 w-full items-center justify-center gap-2 rounded-lg border border-white/10 text-xs font-medium text-slate-200 hover:bg-white/5"
                    >
                        <Sparkles className="h-3.5 w-3.5" />
                        General Prompt
                    </button>
                </section>
            </div>
        </div>
    );

    return (
        <div onClick={closeOverlaysFromBackdrop}>
            <JarvisWorkspaceLayout
                title={conversation?.title ?? 'Jarvis'}
                header={header}
                sidebar={sidebar}
                context={contextPanel}
                sidebarOpen={sidebarOpen}
                contextCollapsed={contextCollapsed}
                contextDrawer={contextDrawer}
            >
                {mode === 'voice' ? (
                    <Suspense
                        fallback={
                            <div className="flex h-full items-center justify-center text-sm text-slate-400">
                                Loading Voice…
                            </div>
                        }
                    >
                        <VoiceSession
                            conversationId={conversation?.id}
                            onSwitchToText={() => setMode('text')}
                            onTurn={(payload, optimisticId, clientMessageId) => applyTurnPayload(payload, optimisticId, clientMessageId)}
                        />
                    </Suspense>
                ) : (
                    <div className="flex h-full min-h-0 flex-col">
                        <div ref={scrollerRef} className="min-h-0 flex-1 overflow-y-auto px-4 py-5 sm:px-8">
                            {hasMore ? (
                                <div className="mb-4 flex justify-center">
                                    <button
                                        type="button"
                                        disabled={loadingOlder}
                                        onClick={loadOlder}
                                        className="rounded-full border border-white/10 bg-white/5 px-3 py-1.5 text-xs font-medium text-slate-300 hover:bg-white/10 disabled:opacity-60"
                                    >
                                        {loadingOlder ? 'Loading…' : 'Load older'}
                                    </button>
                                </div>
                            ) : null}

                            {empty ? (
                                <div className="flex h-full flex-col items-center justify-center gap-6">
                                    <p className="text-2xl font-medium tracking-tight text-white">Чем займёмся?</p>
                                    <div className="flex max-w-xl flex-wrap justify-center gap-2">
                                        {SUGGESTIONS.map((chip) => (
                                            <button
                                                key={chip}
                                                type="button"
                                                onClick={() => sendBody(chip)}
                                                className="rounded-full border border-white/10 bg-white/5 px-3 py-1.5 text-xs text-slate-300 hover:border-sky-400/40 hover:text-white"
                                            >
                                                {chip}
                                            </button>
                                        ))}
                                    </div>
                                </div>
                            ) : (
                                <div className="mx-auto flex max-w-3xl flex-col gap-3">
                                    {messages.map((message) => (
                                        <Bubble
                                            key={message.id}
                                            message={message}
                                            time={formatWhen(message.occurred_at, timezone)}
                                            sending={sending}
                                            retentionHours={retentionHours}
                                            onOpenImage={setLightbox}
                                            onConfirm={() => resolveConfirmation(message.pending_confirmation?.id, true)}
                                            onCancel={() => resolveConfirmation(message.pending_confirmation?.id, false)}
                                        />
                                    ))}
                                    {sending ? (
                                        <div className="flex items-center gap-2 text-sm text-slate-400" aria-live="polite">
                                            <Loader2 className="h-4 w-4 animate-spin" />
                                            Jarvis is thinking...
                                        </div>
                                    ) : null}
                                </div>
                            )}
                        </div>

                        <div className="border-t border-white/10 bg-black/30 px-4 py-3 sm:px-8">
                            {error ? <p className="mb-2 text-sm text-rose-300">{error}</p> : null}
                            {flash?.success ? <p className="mb-2 text-sm text-emerald-300">{flash.success}</p> : null}
                            <form
                                className="mx-auto max-w-3xl"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    sendBody(draft);
                                }}
                                onDragOver={(event) => event.preventDefault()}
                                onDrop={handleDrop}
                            >
                                {pendingFiles.length > 0 ? (
                                    <div className="mb-2 flex flex-wrap gap-2">
                                        {pendingFiles.map((item) => (
                                            item.kind === 'file' ? (
                                                <div key={item.id} className="relative flex h-16 min-w-[9rem] items-center gap-2 rounded-xl bg-white/5 px-3 ring-1 ring-white/15">
                                                    <FileText className="h-4 w-4 text-sky-300" />
                                                    <div className="min-w-0">
                                                        <p className="truncate text-xs text-white">{item.name}</p>
                                                        <p className="text-[10px] text-slate-400">{formatBytes(item.size)} · Storage</p>
                                                    </div>
                                                    <button
                                                        type="button"
                                                        onClick={() => removePendingFile(item.id)}
                                                        className="absolute right-0.5 top-0.5 rounded-full bg-black/70 p-0.5 text-white"
                                                        aria-label="Remove attachment"
                                                        disabled={sending}
                                                    >
                                                        <X className="h-3 w-3" />
                                                    </button>
                                                </div>
                                            ) : (
                                                <div key={item.id} className="relative h-16 w-16 overflow-hidden rounded-xl ring-1 ring-white/15">
                                                    <img src={item.url} alt="" className="h-full w-full object-cover" />
                                                    <button
                                                        type="button"
                                                        onClick={() => removePendingFile(item.id)}
                                                        className="absolute right-0.5 top-0.5 rounded-full bg-black/70 p-0.5 text-white"
                                                        aria-label="Remove attachment"
                                                        disabled={sending}
                                                    >
                                                        <X className="h-3 w-3" />
                                                    </button>
                                                </div>
                                            )
                                        ))}
                                    </div>
                                ) : null}
                                <div className="flex items-end gap-2">
                                    <input
                                        ref={fileInputRef}
                                        type="file"
                                        accept={acceptTypes}
                                        multiple
                                        hidden
                                        onChange={(event) => {
                                            addPendingFiles(event.target.files);
                                            event.target.value = '';
                                        }}
                                    />
                                    <button
                                        type="button"
                                        disabled={sending || maxImages < 1}
                                        onClick={() => fileInputRef.current?.click()}
                                        className="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl border border-white/10 bg-white/5 text-slate-300 hover:text-white disabled:opacity-50"
                                        aria-label="Attach image"
                                    >
                                        <Paperclip className="h-4 w-4" />
                                    </button>
                                    <textarea
                                        ref={composerRef}
                                        value={draft}
                                        rows={1}
                                        placeholder="Сообщение, файл или Ctrl+V для скрина"
                                        disabled={sending}
                                        onChange={(event) => setDraft(event.target.value)}
                                        onPaste={handleClipboardPaste}
                                        onKeyDown={(event) => {
                                            if (event.key === 'Enter' && !event.shiftKey) {
                                                event.preventDefault();
                                                sendBody(draft);
                                            }
                                        }}
                                        className="max-h-40 min-h-[48px] flex-1 resize-none rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-slate-100 shadow-inner outline-none placeholder:text-slate-500 focus:border-sky-400/40 disabled:opacity-60"
                                    />
                                    <button
                                        type="submit"
                                        disabled={sending || (!draft.trim() && pendingFiles.length === 0)}
                                        className="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-sky-500 text-white hover:bg-sky-400 disabled:opacity-50"
                                        aria-label="Send"
                                    >
                                        {sending ? <Loader2 className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                )}
            </JarvisWorkspaceLayout>

            {promptOpen ? (
                <Modal title="General Prompt" onClose={() => setPromptOpen(false)}>
                    <p className="mb-3 text-sm text-slate-400">
                        Personal assistant instruction. Provider and model stay in Admin.
                    </p>
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            promptForm.patch(route('jarvis.settings.prompt.update'), {
                                preserveScroll: true,
                                onSuccess: () => setPromptOpen(false),
                            });
                        }}
                    >
                        <textarea
                            value={promptForm.data.general_prompt ?? ''}
                            onChange={(event) => promptForm.setData('general_prompt', event.target.value)}
                            rows={10}
                            className="w-full rounded-xl border border-white/10 bg-black/30 p-3 text-sm text-slate-100 outline-none focus:border-sky-400/40"
                        />
                        <div className="mt-4 flex justify-end gap-2">
                            <button type="button" onClick={() => setPromptOpen(false)} className="rounded-lg px-3 py-2 text-sm text-slate-400 hover:text-white">
                                Cancel
                            </button>
                            <button
                                type="submit"
                                disabled={promptForm.processing}
                                className="rounded-lg bg-sky-500 px-3 py-2 text-sm font-medium text-white hover:bg-sky-400"
                            >
                                Save
                            </button>
                        </div>
                    </form>
                </Modal>
            ) : null}

            {settingsOpen ? (
                <Modal title="Workspace settings" onClose={() => setSettingsOpen(false)}>
                    <dl className="space-y-3 text-sm">
                        <div>
                            <dt className="text-xs uppercase tracking-[0.14em] text-slate-500">Timezone</dt>
                            <dd className="mt-1 text-slate-200">{user.timezone || '—'}</dd>
                        </div>
                        <div>
                            <dt className="text-xs uppercase tracking-[0.14em] text-slate-500">Voice</dt>
                            <dd className="mt-1 text-slate-400">Coming next. No microphone access in this milestone.</dd>
                        </div>
                    </dl>
                    <div className="mt-5 flex flex-wrap gap-2">
                        <button
                            type="button"
                            onClick={() => {
                                setSettingsOpen(false);
                                setPromptOpen(true);
                            }}
                            className="rounded-lg border border-white/10 px-3 py-2 text-sm text-slate-200 hover:bg-white/5"
                        >
                            Edit General Prompt
                        </button>
                        <Link
                            href={route('settings.index', { tab: 'integrations' })}
                            className="rounded-lg border border-white/10 px-3 py-2 text-sm text-slate-200 hover:bg-white/5"
                        >
                            Integrations in Admin
                        </Link>
                    </div>
                </Modal>
            ) : null}

            {lightbox ? (
                <div
                    className="fixed inset-0 z-[60] flex items-center justify-center bg-black/80 p-4"
                    onClick={() => {
                        setLightbox(null);
                        focusComposer(composerRef.current, { forceDesktopOnly: true });
                    }}
                >
                    <img
                        src={lightbox.view_url || lightbox.preview_url}
                        alt=""
                        className="max-h-full max-w-full rounded-xl object-contain shadow-2xl"
                        onClick={(event) => event.stopPropagation()}
                    />
                    <button
                        type="button"
                        onClick={() => {
                            setLightbox(null);
                            focusComposer(composerRef.current, { forceDesktopOnly: true });
                        }}
                        className="absolute right-4 top-4 rounded-lg bg-black/60 p-2 text-white"
                        aria-label="Close image"
                    >
                        <X className="h-5 w-5" />
                    </button>
                </div>
            ) : null}
        </div>
    );
}

function Modal({ title, onClose, children }) {
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" onClick={onClose}>
            <div
                className="w-full max-w-lg rounded-2xl border border-white/10 bg-[#10182a] p-5 shadow-2xl"
                onClick={(event) => event.stopPropagation()}
                role="dialog"
                aria-modal="true"
                aria-label={title}
            >
                <div className="mb-3 flex items-center justify-between">
                    <h2 className="text-base font-semibold text-white">{title}</h2>
                    <button type="button" onClick={onClose} className="rounded-lg p-1 text-slate-400 hover:text-white" aria-label="Close">
                        <X className="h-4 w-4" />
                    </button>
                </div>
                {children}
            </div>
        </div>
    );
}

function Bubble({ message, time, sending = false, onConfirm, onCancel, onOpenImage, retentionHours = 24 }) {
    if (message.kind === 'error' || message.status === 'failed') {
        return (
            <div className="mx-auto max-w-xl rounded-xl border border-rose-500/30 bg-rose-500/10 px-4 py-2 text-center text-sm text-rose-200">
                {message.body}
            </div>
        );
    }

    const mine = message.kind === 'user';
    const pending = message.pending_confirmation;
    const streaming = message.status === 'streaming';

    return (
        <div className={`flex ${mine ? 'justify-end' : 'justify-start'}`}>
            <div
                className={`max-w-[92%] rounded-2xl px-4 py-2 sm:max-w-[78%] ${
                    mine
                        ? 'bg-sky-500/20 text-sky-50 ring-1 ring-sky-400/20'
                        : 'bg-white/5 text-slate-100 ring-1 ring-white/10'
                } ${message.pending || message.status === 'pending' ? 'opacity-70' : ''}`}
            >
                {mine ? (
                    message.body ? (
                        <p className="whitespace-pre-wrap break-words text-sm leading-6">{message.body}</p>
                    ) : null
                ) : (
                    <SafeMarkdown text={message.body} />
                )}
                {Array.isArray(message.attachments) && message.attachments.length > 0 ? (
                    <div className={`mt-2 flex flex-wrap gap-2 ${mine ? 'justify-end' : 'justify-start'}`}>
                        {message.attachments.map((attachment) => (
                            attachment.purged ? (
                                <ExpiredScreenshotCard key={attachment.id} attachment={attachment} />
                            ) : (
                                <button
                                    key={attachment.id}
                                    type="button"
                                    onClick={() => onOpenImage?.(attachment)}
                                    className="overflow-hidden rounded-xl ring-1 ring-white/15"
                                    aria-label="Open image"
                                >
                                    <img
                                        src={attachment.preview_url || attachment.view_url}
                                        alt=""
                                        className="h-20 w-20 object-cover"
                                    />
                                    <p className="max-w-[10rem] px-2 py-1 text-[10px] text-slate-400">
                                        Temporary image · expires in ~{retentionHours}h
                                    </p>
                                </button>
                            )
                        ))}
                    </div>
                ) : null}
                {Array.isArray(message.stored_files) && message.stored_files.length > 0 ? (
                    <div className={`mt-2 flex flex-wrap gap-2 ${mine ? 'justify-end' : 'justify-start'}`}>
                        {message.stored_files.map((file) => (
                            <Link
                                key={file.public_id}
                                href={route('jarvis.storage.show', file.public_id)}
                                className="flex min-w-[10rem] items-center gap-2 rounded-xl bg-black/20 px-3 py-2 ring-1 ring-emerald-400/20"
                            >
                                <FileText className="h-4 w-4 text-emerald-300" />
                                <div className="min-w-0">
                                    <p className="truncate text-xs text-white">{file.display_name}</p>
                                    <p className="text-[10px] text-emerald-200/80">Saved to Storage · {formatBytes(file.size_bytes)}</p>
                                </div>
                            </Link>
                        ))}
                    </div>
                ) : null}
                {streaming ? <p className="mt-1 text-[11px] text-slate-500">streaming…</p> : null}
                {pending?.id && !mine ? <ConfirmationCard pending={pending} sending={sending} onConfirm={onConfirm} onCancel={onCancel} /> : null}
                <p className={`mt-1 text-[11px] ${mine ? 'text-sky-200/70' : 'text-slate-500'}`}>{time}</p>
            </div>
        </div>
    );
}

function ExpiredScreenshotCard({ attachment }) {
    const [open, setOpen] = useState(false);
    const summary = String(attachment.summary_text || '').trim();

    return (
        <div className="max-w-[16rem] rounded-xl bg-black/30 px-3 py-2 ring-1 ring-white/10">
            <p className="text-xs font-medium text-slate-200">Screenshot expired</p>
            {summary ? (
                <>
                    <p className={`mt-1 text-[11px] text-slate-400 ${open ? '' : 'line-clamp-3'}`}>{summary}</p>
                    {summary.length > 120 ? (
                        <button type="button" onClick={() => setOpen((value) => !value)} className="mt-1 text-[11px] text-sky-300">
                            {open ? 'Collapse' : 'Expand'}
                        </button>
                    ) : null}
                </>
            ) : (
                <p className="mt-1 text-[11px] text-slate-500">Screenshot expired; visual summary unavailable.</p>
            )}
        </div>
    );
}

function ConfirmationCard({ pending, sending, onConfirm, onCancel }) {
    const preview = pending.preview || {};

    return (
        <div className="mt-3 rounded-xl border border-amber-400/20 bg-amber-400/5 p-3">
            <p className="text-[11px] uppercase tracking-[0.14em] text-amber-200/80">{providerLabel(pending.tool_name)}</p>
            {pending.summary ? <p className="mt-1 text-sm text-slate-200">{pending.summary}</p> : null}
            {preview.to?.length ? <p className="mt-2 text-xs text-slate-400">To: {preview.to.join(', ')}</p> : null}
            {preview.cc?.length ? <p className="text-xs text-slate-400">Cc: {preview.cc.join(', ')}</p> : null}
            {preview.subject ? <p className="text-xs text-slate-400">Subject: {preview.subject}</p> : null}
            {preview.body_preview ? (
                <p className="mt-1 whitespace-pre-wrap text-xs text-slate-300">{preview.body_preview}</p>
            ) : null}
            {Object.entries(preview)
                .filter(([key]) => !['to', 'cc', 'subject', 'body_preview'].includes(key))
                .map(([key, value]) => (
                    <p key={key} className="text-xs text-slate-400">
                        {key.replaceAll('_', ' ')}: {String(value)}
                    </p>
                ))}
            {pending.expires_at ? (
                <p className="mt-2 text-[11px] text-slate-500">Expires {formatWhen(pending.expires_at)}</p>
            ) : null}
            <div className="mt-3 flex gap-2">
                <button
                    type="button"
                    disabled={sending}
                    onClick={onConfirm}
                    className="inline-flex items-center gap-1 rounded-lg bg-emerald-500/90 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-400 disabled:opacity-60"
                >
                    <Check className="h-3.5 w-3.5" />
                    Confirm
                </button>
                <button
                    type="button"
                    disabled={sending}
                    onClick={onCancel}
                    className="rounded-lg border border-white/15 px-3 py-1.5 text-xs font-semibold text-slate-200 hover:bg-white/5 disabled:opacity-60"
                >
                    Cancel
                </button>
            </div>
        </div>
    );
}
