export default function PanelSection({ title, empty = 'Пока пусто.', children, count = null }) {
    const isEmpty = count === null ? false : count === 0;

    return (
        <section>
            <h3 className="mb-2 text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{title}</h3>
            {isEmpty ? <p className="text-sm text-slate-500">{empty}</p> : <ul className="space-y-2">{children}</ul>}
        </section>
    );
}
