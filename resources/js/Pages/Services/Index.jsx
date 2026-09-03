import AdminLayout from '@/Layouts/AdminLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

const DEFAULT_FORM = {
    name: '',
    description: '',
    price: '',
    duration_minutes: '',
    is_active: true,
};

export default function ServicesIndex({ services = [] }) {
    const [editingService, setEditingService] = useState(null);
    const createForm = useForm({ ...DEFAULT_FORM });
    const editForm = useForm({ ...DEFAULT_FORM });

    const startEdit = (service) => {
        setEditingService(service);
        editForm.setData({
            name: service.name ?? '',
            description: service.description ?? '',
            price: service.price ?? '',
            duration_minutes: service.duration_minutes ?? '',
            is_active: !!service.is_active,
        });
        editForm.clearErrors();
    };

    return (
        <AdminLayout title="Services">
            <Head title="Services" />

            <div className="space-y-6">
                <section className="app-widget p-4">
                    <h2 className="text-base font-semibold text-slate-900">Create service</h2>
                    <form
                        className="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            createForm.post(route('services.store'), {
                                preserveScroll: true,
                                onSuccess: () => createForm.setData({ ...DEFAULT_FORM }),
                            });
                        }}
                    >
                        <Field label="Name" value={createForm.data.name} onChange={(v) => createForm.setData('name', v)} error={createForm.errors.name} />
                        <Field label="Price" type="number" value={createForm.data.price} onChange={(v) => createForm.setData('price', v)} error={createForm.errors.price} />
                        <Field label="Duration minutes" type="number" value={createForm.data.duration_minutes} onChange={(v) => createForm.setData('duration_minutes', v)} error={createForm.errors.duration_minutes} />
                        <div className="flex items-center gap-2 pt-7">
                            <input
                                id="service-active"
                                type="checkbox"
                                checked={!!createForm.data.is_active}
                                onChange={(e) => createForm.setData('is_active', e.target.checked)}
                            />
                            <label htmlFor="service-active" className="text-sm text-slate-700">Active</label>
                        </div>
                        <div className="md:col-span-2">
                            <label className="mb-1 block text-sm font-medium text-slate-600">Description</label>
                            <textarea
                                rows={3}
                                value={createForm.data.description}
                                onChange={(e) => createForm.setData('description', e.target.value)}
                                className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                            />
                        </div>
                        <div className="md:col-span-2 flex justify-end">
                            <button type="submit" className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">Save</button>
                        </div>
                    </form>
                </section>

                <section className="app-widget p-4">
                    <h2 className="text-base font-semibold text-slate-900">Services list</h2>
                    <div className="mt-4 overflow-x-auto rounded-lg border border-slate-200 bg-white">
                        <table className="min-w-full divide-y divide-slate-200 text-sm">
                            <thead className="bg-slate-50">
                                <tr>
                                    <Th>Name</Th><Th>Price</Th><Th>Duration</Th><Th>Active</Th><Th>Actions</Th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {services.map((service) => (
                                    <tr key={service.id}>
                                        <Td>{service.name}</Td>
                                        <Td>{service.price ?? '—'}</Td>
                                        <Td>{service.duration_minutes ?? '—'}</Td>
                                        <Td>{service.is_active ? 'yes' : 'no'}</Td>
                                        <Td>
                                            <div className="flex gap-3">
                                                <button type="button" className="text-indigo-700" onClick={() => startEdit(service)}>Edit</button>
                                                <button
                                                    type="button"
                                                    className="text-red-700"
                                                    onClick={() => {
                                                        if (window.confirm('Delete service?')) {
                                                            router.delete(route('services.destroy', service.id), {
                                                                preserveScroll: true,
                                                            });
                                                        }
                                                    }}
                                                >
                                                    Delete
                                                </button>
                                            </div>
                                        </Td>
                                    </tr>
                                ))}
                                {services.length === 0 && (
                                    <tr>
                                        <td className="px-4 py-5 text-slate-500" colSpan={5}>No services yet.</td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            {editingService && (
                <Modal title="Edit service" onClose={() => setEditingService(null)}>
                    <form
                        className="grid grid-cols-1 gap-3 md:grid-cols-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            editForm.patch(route('services.update', editingService.id), {
                                preserveScroll: true,
                                onSuccess: () => setEditingService(null),
                            });
                        }}
                    >
                        <Field label="Name" value={editForm.data.name} onChange={(v) => editForm.setData('name', v)} error={editForm.errors.name} />
                        <Field label="Price" type="number" value={editForm.data.price} onChange={(v) => editForm.setData('price', v)} error={editForm.errors.price} />
                        <Field label="Duration minutes" type="number" value={editForm.data.duration_minutes} onChange={(v) => editForm.setData('duration_minutes', v)} error={editForm.errors.duration_minutes} />
                        <div className="flex items-center gap-2 pt-7">
                            <input
                                id="service-edit-active"
                                type="checkbox"
                                checked={!!editForm.data.is_active}
                                onChange={(e) => editForm.setData('is_active', e.target.checked)}
                            />
                            <label htmlFor="service-edit-active" className="text-sm text-slate-700">Active</label>
                        </div>
                        <div className="md:col-span-2">
                            <label className="mb-1 block text-sm font-medium text-slate-600">Description</label>
                            <textarea
                                rows={3}
                                value={editForm.data.description}
                                onChange={(e) => editForm.setData('description', e.target.value)}
                                className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                            />
                        </div>
                        <div className="md:col-span-2 flex justify-end gap-2">
                            <button type="button" onClick={() => setEditingService(null)} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700">Cancel</button>
                            <button type="submit" className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">Update</button>
                        </div>
                    </form>
                </Modal>
            )}
        </AdminLayout>
    );
}

function Modal({ title, children, onClose }) {
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4">
            <div className="w-full max-w-3xl rounded-xl border border-slate-200 bg-white p-6 shadow-xl">
                <div className="mb-4 flex items-center justify-between">
                    <h3 className="text-base font-semibold text-slate-900">{title}</h3>
                    <button type="button" className="text-sm text-slate-500" onClick={onClose}>Close</button>
                </div>
                {children}
            </div>
        </div>
    );
}

function Field({ label, value, onChange, error, type = 'text' }) {
    return (
        <div>
            <label className="mb-1 block text-sm font-medium text-slate-600">{label}</label>
            <input
                type={type}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                className="h-10 w-full rounded-lg border border-slate-300 px-3 text-sm"
            />
            {error && <p className="mt-1 text-sm text-red-600">{error}</p>}
        </div>
    );
}

function Th({ children }) {
    return <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{children}</th>;
}

function Td({ children }) {
    return <td className="px-4 py-3 text-slate-700">{children}</td>;
}
