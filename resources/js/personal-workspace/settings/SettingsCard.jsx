export default function SettingsCard({ title, description, children }) {
    return (
        <section className="rounded-2xl border border-white/10 bg-white/5 p-4">
            {title ? (
                <div className="mb-3">
                    <h3 className="text-sm font-semibold text-white">{title}</h3>
                    {description ? <p className="mt-1 text-xs leading-5 text-slate-400">{description}</p> : null}
                </div>
            ) : null}
            {children}
        </section>
    );
}
