import AdminLayout from '@/Layouts/AdminLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

const DEFAULT_FORM = {
    name: '',
    email: '',
    phone: '',
    address: '',
    status: 'active',
    notes: '',
};

export default function CustomersIndex({ customers = [] }) {
    const [editingCustomer, setEditingCustomer] = useState(null);
    const createForm = useForm({ ...DEFAULT_FORM });
    const editForm = useForm({ ...DEFAULT_FORM });

    const startEdit = (customer) => {
        setEditingCustomer(customer);
        editForm.setData({
            name: customer.name ?? '',
            email: customer.email ?? '',
            phone: customer.phone ?? '',
            address: customer.address ?? '',
            status: customer.status ?? 'active',
            notes: customer.notes ?? '',
        });
        editForm.clearErrors();
    };

    return (
        <AdminLayout title="Customers">
            <Head title="Customers" />

            <div className="space-y-6">
                <section className="app-widget p-4">
                    <h2 className="text-base font-semibold text-slate-900">Create customer</h2>
                    <form
                        className="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            createForm.post(route('customers.store'), {
                                preserveScroll: true,
                                onSuccess: () => createForm.setData({ ...DEFAULT_FORM }),
                            });
                        }}
                    >
                        <Field label="Name" value={createForm.data.name} onChange={(v) => createForm.setData('name', v)} error={createForm.errors.name} />
                        <Field label="Email" value={createForm.data.email} onChange={(v) => createForm.setData('email', v)} error={createForm.errors.email} type="email" />
                        <Field label="Phone" value={createForm.data.phone} onChange={(v) => createForm.setData('phone', v)} error={createForm.errors.phone} />
                        <Field label="Address" value={createForm.data.address} onChange={(v) => createForm.setData('address', v)} error={createForm.errors.address} />
                        <div>
                            <label className="mb-1 block text-sm font-medium text-slate-600">Status</label>
                            <select
                                value={createForm.data.status}
                                onChange={(e) => createForm.setData('status', e.target.value)}
                                className="h-10 w-full rounded-lg border border-slate-300 px-3 text-sm"
                            >
                                <option value="active">active</option>
                                <option value="inactive">inactive</option>
                            </select>
                        </div>
                        <div className="md:col-span-2">
                            <label className="mb-1 block text-sm font-medium text-slate-600">Notes</label>
                            <textarea
                                value={createForm.data.notes}
                                onChange={(e) => createForm.setData('notes', e.target.value)}
                                rows={3}
                                className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                            />
                        </div>
                        <div className="md:col-span-2 flex justify-end">
                            <button type="submit" className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">
                                Save
                            </button>
                        </div>
                    </form>
                </section>

                <section className="app-widget p-4">
                    <h2 className="text-base font-semibold text-slate-900">Customers list</h2>
                    <div className="mt-4 overflow-x-auto rounded-lg border border-slate-200 bg-white">
                        <table className="min-w-full divide-y divide-slate-200 text-sm">
                            <thead className="bg-slate-50">
                                <tr>
                                    <Th>Name</Th><Th>Email</Th><Th>Phone</Th><Th>Status</Th><Th>Actions</Th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {customers.map((customer) => (
                                    <tr key={customer.id}>
                                        <Td>{customer.name}</Td>
                                        <Td>{customer.email || '—'}</Td>
                                        <Td>{customer.phone || '—'}</Td>
                                        <Td>{customer.status}</Td>
                                        <Td>
                                            <div className="flex gap-3">
                                                <button type="button" className="text-indigo-700" onClick={() => startEdit(customer)}>Edit</button>
                                                <button
                                                    type="button"
                                                    className="text-red-700"
                                                    onClick={() => {
                                                        if (window.confirm('Delete customer?')) {
                                                            router.delete(route('customers.destroy', customer.id), {
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
                                {customers.length === 0 && (
                                    <tr>
                                        <td className="px-4 py-5 text-slate-500" colSpan={5}>No customers yet.</td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            {editingCustomer && (
                <Modal title="Edit customer" onClose={() => setEditingCustomer(null)}>
                    <form
                        className="grid grid-cols-1 gap-3 md:grid-cols-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            editForm.patch(route('customers.update', editingCustomer.id), {
                                preserveScroll: true,
                                onSuccess: () => setEditingCustomer(null),
                            });
                        }}
                    >
                        <Field label="Name" value={editForm.data.name} onChange={(v) => editForm.setData('name', v)} error={editForm.errors.name} />
                        <Field label="Email" value={editForm.data.email} onChange={(v) => editForm.setData('email', v)} error={editForm.errors.email} type="email" />
                        <Field label="Phone" value={editForm.data.phone} onChange={(v) => editForm.setData('phone', v)} error={editForm.errors.phone} />
                        <Field label="Address" value={editForm.data.address} onChange={(v) => editForm.setData('address', v)} error={editForm.errors.address} />
                        <div>
                            <label className="mb-1 block text-sm font-medium text-slate-600">Status</label>
                            <select
                                value={editForm.data.status}
                                onChange={(e) => editForm.setData('status', e.target.value)}
                                className="h-10 w-full rounded-lg border border-slate-300 px-3 text-sm"
                            >
                                <option value="active">active</option>
                                <option value="inactive">inactive</option>
                            </select>
                        </div>
                        <div className="md:col-span-2">
                            <label className="mb-1 block text-sm font-medium text-slate-600">Notes</label>
                            <textarea
                                value={editForm.data.notes}
                                onChange={(e) => editForm.setData('notes', e.target.value)}
                                rows={3}
                                className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                            />
                        </div>
                        <div className="md:col-span-2 flex justify-end gap-2">
                            <button type="button" onClick={() => setEditingCustomer(null)} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700">Cancel</button>
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

function Th({ children }) {
    return <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{children}</th>;
}

function Td({ children }) {
    return <td className="px-4 py-3 text-slate-700">{children}</td>;
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
