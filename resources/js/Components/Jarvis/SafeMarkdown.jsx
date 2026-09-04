import { useState } from 'react';

const HTTP_URL = /^https?:\/\//i;

function splitFenced(text) {
    const parts = [];
    const pattern = /```([a-zA-Z0-9_-]*)\n?([\s\S]*?)```/g;
    let last = 0;
    let match;

    while ((match = pattern.exec(text)) !== null) {
        if (match.index > last) {
            parts.push({ type: 'markdown', value: text.slice(last, match.index) });
        }

        parts.push({
            type: 'code',
            language: match[1] || '',
            value: match[2].replace(/\n$/, ''),
        });
        last = match.index + match[0].length;
    }

    if (last < text.length) {
        parts.push({ type: 'markdown', value: text.slice(last) });
    }

    return parts.length > 0 ? parts : [{ type: 'markdown', value: text }];
}

function Inline({ text }) {
    const nodes = [];
    const pattern = /(\[([^\]]+)\]\(([^)]+)\)|`([^`]+)`|\*\*([^*]+)\*\*|\*([^*]+)\*)/g;
    let last = 0;
    let match;
    let key = 0;

    while ((match = pattern.exec(text)) !== null) {
        if (match.index > last) {
            nodes.push(text.slice(last, match.index));
        }

        if (match[2] && match[3]) {
            const href = match[3].trim();
            if (HTTP_URL.test(href)) {
                nodes.push(
                    <a
                        key={`l-${key++}`}
                        href={href}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="text-sky-300 underline decoration-sky-500/40 underline-offset-2 hover:text-sky-200"
                    >
                        {match[2]}
                    </a>,
                );
            } else {
                nodes.push(match[0]);
            }
        } else if (match[4]) {
            nodes.push(
                <code key={`c-${key++}`} className="rounded bg-black/40 px-1.5 py-0.5 font-mono text-[0.85em] text-amber-100">
                    {match[4]}
                </code>,
            );
        } else if (match[5]) {
            nodes.push(
                <strong key={`b-${key++}`} className="font-semibold text-white">
                    {match[5]}
                </strong>,
            );
        } else if (match[6]) {
            nodes.push(
                <em key={`i-${key++}`} className="italic">
                    {match[6]}
                </em>,
            );
        }

        last = match.index + match[0].length;
    }

    if (last < text.length) {
        nodes.push(text.slice(last));
    }

    return <>{nodes}</>;
}

function MarkdownBlock({ text }) {
    const lines = text.replace(/\r\n/g, '\n').split('\n');
    const blocks = [];
    let i = 0;

    while (i < lines.length) {
        const line = lines[i];

        if (line.trim() === '') {
            i += 1;
            continue;
        }

        if (/^\|/.test(line) && i + 1 < lines.length && /^\|?\s*-+/.test(lines[i + 1])) {
            const rows = [];
            while (i < lines.length && /^\|/.test(lines[i])) {
                if (/^\|?\s*-+/.test(lines[i])) {
                    i += 1;
                    continue;
                }
                rows.push(
                    lines[i]
                        .replace(/^\||\|$/g, '')
                        .split('|')
                        .map((cell) => cell.trim()),
                );
                i += 1;
            }
            if (rows.length > 0) {
                const header = rows[0];
                const body = rows.slice(1);
                blocks.push(
                    <div key={`t-${i}`} className="my-2 overflow-x-auto">
                        <table className="min-w-full border-collapse text-left text-xs">
                            <thead>
                                <tr>
                                    {header.map((cell, idx) => (
                                        <th key={idx} className="border-b border-white/15 px-2 py-1.5 font-semibold text-slate-200">
                                            <Inline text={cell} />
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {body.map((row, rIdx) => (
                                    <tr key={rIdx}>
                                        {row.map((cell, cIdx) => (
                                            <td key={cIdx} className="border-b border-white/8 px-2 py-1.5 text-slate-300">
                                                <Inline text={cell} />
                                            </td>
                                        ))}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>,
                );
            }
            continue;
        }

        const listMatch = line.match(/^(\s*)([-*]|\d+\.)\s+(.*)$/);
        if (listMatch) {
            const ordered = /^\d+\./.test(listMatch[2]);
            const items = [];
            while (i < lines.length) {
                const item = lines[i].match(/^(\s*)([-*]|\d+\.)\s+(.*)$/);
                if (!item) {
                    break;
                }
                items.push(item[3]);
                i += 1;
            }
            const ListTag = ordered ? 'ol' : 'ul';
            blocks.push(
                <ListTag
                    key={`ls-${i}`}
                    className={`my-2 space-y-1 pl-5 text-sm leading-6 text-slate-200 ${ordered ? 'list-decimal' : 'list-disc'}`}
                >
                    {items.map((item, idx) => (
                        <li key={idx}>
                            <Inline text={item} />
                        </li>
                    ))}
                </ListTag>,
            );
            continue;
        }

        const heading = line.match(/^(#{1,3})\s+(.*)$/);
        if (heading) {
            const Tag = `h${heading[1].length + 2}`;
            blocks.push(
                <Tag key={`h-${i}`} className="mt-3 mb-1 font-semibold text-white">
                    <Inline text={heading[2]} />
                </Tag>,
            );
            i += 1;
            continue;
        }

        const para = [line];
        i += 1;
        while (i < lines.length && lines[i].trim() !== '' && !/^```/.test(lines[i]) && !/^(\s*)([-*]|\d+\.)\s+/.test(lines[i]) && !/^#{1,3}\s+/.test(lines[i]) && !/^\|/.test(lines[i])) {
            para.push(lines[i]);
            i += 1;
        }

        blocks.push(
            <p key={`p-${i}`} className="whitespace-pre-wrap break-words text-sm leading-6 text-slate-200">
                <Inline text={para.join('\n')} />
            </p>,
        );
    }

    return <div className="space-y-1">{blocks}</div>;
}

function CodeBlock({ language, value }) {
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(value);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 1600);
        } catch {
            setCopied(false);
        }
    };

    return (
        <div className="group relative my-2 overflow-hidden rounded-xl border border-white/10 bg-black/50">
            <div className="flex items-center justify-between border-b border-white/10 px-3 py-1.5 text-[10px] uppercase tracking-[0.16em] text-slate-500">
                <span>{language || 'code'}</span>
                <button
                    type="button"
                    onClick={copy}
                    className="rounded-md px-2 py-0.5 text-[10px] text-slate-400 hover:bg-white/10 hover:text-white"
                    aria-label="Copy code"
                >
                    {copied ? 'Copied' : 'Copy'}
                </button>
            </div>
            <pre className="overflow-x-auto p-3 font-mono text-[12px] leading-5 text-slate-100">
                <code>{value}</code>
            </pre>
        </div>
    );
}

export default function SafeMarkdown({ text }) {
    if (!text) {
        return null;
    }

    return (
        <div className="jarvis-markdown">
            {splitFenced(text).map((part, index) =>
                part.type === 'code' ? (
                    <CodeBlock key={index} language={part.language} value={part.value} />
                ) : (
                    <MarkdownBlock key={index} text={part.value} />
                ),
            )}
        </div>
    );
}
