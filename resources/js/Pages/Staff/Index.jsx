import AdminLayout from '@/Layouts/AdminLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

const DEFAULT_FORM = {
    name: '',
    email: '',
    phone: '',
    role: '',
    is_active: true,
    notes: '',
};

export default function StaffIndex({ staff = [] }) {
    const [editingStaff, setEditingStaff] = useState(null);
    const createForm = useForm({ ...DEFAULT_FORM });
    const editForm = useForm({ ...DEFAULT_FORM });

    const startEdit = (member) => {
        setEditingStaff(member);
        editForm.setData({
            name: member.name ?? '',
            email: member.email ?? '',
            phone: member.phone ?? '',
            role: member.role ?? '',
            is_active: !!member.is_active,
            notes: member.notes ?? '',
        });
        editForm.clearErrors();
    };

    return (
        <AdminLayout title="Staff">
            <Head title="Staff" />

            <div className="space-y-6">
                <section className="app-widget p-4">
                    <h2 className="text-base font-semibold text-slate-900">Create staff member</h2>
                    <form
                        className="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            createForm.post(route('staff.store'), {
                                preserveScroll: true,
                                onSuccess: () => createForm.setData({ ...DEFAULT_FORM }),
                            });
                        }}
                    >
                        <Field label="Name" value={createForm.data.name} onChange={(v) => createForm.setData('name', v)} error={createForm.errors.name} />
                        <Field label="Role" value={createForm.data.role} onChange={(v) => createForm.setData('role', v)} error={createForm.errors.role} />
                        <Field label="Email" type="email" value={createForm.data.email} onChange={(v) => createForm.setData('email', v)} error={createForm.errors.email} />
                        <Field label="Phone" value={createForm.data.phone} onChange={(v) => createForm.setData('phone', v)} error={createForm.errors.phone} />
                        <div className="flex items-center gap-2 pt-7">
                            <input
                                id="staff-active"
                                type="checkbox"
                                checked={!!createForm.data.is_active}
                                onChange={(e) => createForm.setData('is_active', e.target.checked)}
                            />
                            <label htmlFor="staff-active" className="text-sm text-slate-700">Active</label>
                        </div>
                        <div className="md:col-span-2">
                            <label className="mb-1 block text-sm font-medium text-slate-600">Notes</label>
                            <textarea
                                rows={3}
                                value={createForm.data.notes}
                                onChange={(e) => createForm.setData('notes', e.target.value)}
                                className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                            />
                        </div>
                        <div className="md:col-span-2 flex justify-end">
                            <button type="submit" className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">Save</button>
                        </div>
                    </form>
                </section>

                <section className="app-widget p-4">
                    <h2 className="text-base font-semibold text-slate-900">Staff list</h2>
                    <div className="mt-4 overflow-x-auto rounded-lg border border-slate-200 bg-white">
                        <table className="min-w-full divide-y divide-slate-200 text-sm">
                            <thead className="bg-slate-50">
                                <tr>
                                    <Th>Name</Th><Th>Email</Th><Th>Phone</Th><Th>Role</Th><Th>Active</Th><Th>Actions</Th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {staff.map((member) => (
                                    <tr key={member.id}>
                                        <Td>{member.name}</Td>
                                        <Td>{member.email || '—'}</Td>
                                        <Td>{member.phone || '—'}</Td>
                                        <Td>{member.role || '—'}</Td>
                                        <Td>{member.is_active ? 'yes' : 'no'}</Td>
                                        <Td>
                                            <div className="flex gap-3">
                                                <button type="button" className="text-indigo-700" onClick={() => startEdit(member)}>Edit</button>
                                                <button
                                                    type="button"
                                                    className="text-red-700"
                                                    onClick={() => {
                                                        if (window.confirm('Delete staff member?')) {
                                                            router.delete(route('staff.destroy', member.id), {
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
                                {staff.length === 0 && (
                                    <tr>
                                        <td className="px-4 py-5 text-slate-500" colSpan={6}>No staff yet.</td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            {editingStaff && (
                <Modal title="Edit staff member" onClose={() => setEditingStaff(null)}>
                    <form
                        className="grid grid-cols-1 gap-3 md:grid-cols-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            editForm.patch(route('staff.update', editingStaff.id), {
                                preserveScroll: true,
                                onSuccess: () => setEditingStaff(null),
                            });
                        }}
                    >
                        <Field label="Name" value={editForm.data.name} onChange={(v) => editForm.setData('name', v)} error={editForm.errors.name} />
                        <Field label="Role" value={editForm.data.role} onChange={(v) => editForm.setData('role', v)} error={editForm.errors.role} />
                        <Field label="Email" type="email" value={editForm.data.email} onChange={(v) => editForm.setData('email', v)} error={editForm.errors.email} />
                        <Field label="Phone" value={editForm.data.phone} onChange={(v) => editForm.setData('phone', v)} error={editForm.errors.phone} />
                        <div className="flex items-center gap-2 pt-7">
                            <input
                                id="staff-edit-active"
                                type="checkbox"
                                checked={!!editForm.data.is_active}
                                onChange={(e) => editForm.setData('is_active', e.target.checked)}
                            />
                            <label htmlFor="staff-edit-active" className="text-sm text-slate-700">Active</label>
                        </div>
                        <div className="md:col-span-2">
                            <label className="mb-1 block text-sm font-medium text-slate-600">Notes</label>
                            <textarea
                                rows={3}
                                value={editForm.data.notes}
                                onChange={(e) => editForm.setData('notes', e.target.value)}
                                className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                            />
                        </div>
                        <div className="md:col-span-2 flex justify-end gap-2">
                            <button type="button" onClick={() => setEditingStaff(null)} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700">Cancel</button>
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
