import AdminLayout from '@/Layouts/AdminLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

const DEFAULT_FORM = {
    customer_id: '',
    service_id: '',
    title: '',
    description: '',
    status: 'new',
    scheduled_at: '',
    completed_at: '',
    total: '',
    notes: '',
    staff_ids: [],
};

export default function OrdersIndex({ orders = [], customers = [], services = [], staff = [], statuses = [] }) {
    const [editingOrder, setEditingOrder] = useState(null);
    const createForm = useForm({ ...DEFAULT_FORM });
    const editForm = useForm({ ...DEFAULT_FORM });

    const startEdit = (order) => {
        setEditingOrder(order);
        editForm.setData({
            customer_id: order.customer_id ?? '',
            service_id: order.service_id ?? '',
            title: order.title ?? '',
            description: order.description ?? '',
            status: order.status ?? 'new',
            scheduled_at: toDatetimeInput(order.scheduled_at),
            completed_at: toDatetimeInput(order.completed_at),
            total: order.total ?? '',
            notes: order.notes ?? '',
            staff_ids: order.staff_ids ?? [],
        });
        editForm.clearErrors();
    };

    const toggleStaff = (form, id) => {
        const current = form.data.staff_ids ?? [];
        form.setData(
            'staff_ids',
            current.includes(id) ? current.filter((item) => item !== id) : [...current, id],
        );
    };

    return (
        <AdminLayout title="Orders">
            <Head title="Orders" />

            <div className="space-y-6">
                <section className="app-widget p-4">
                    <h2 className="text-base font-semibold text-slate-900">Create order</h2>
                    <OrderForm
                        form={createForm}
                        customers={customers}
                        services={services}
                        staff={staff}
                        statuses={statuses}
                        onToggleStaff={(id) => toggleStaff(createForm, id)}
                        onSubmit={(e) => {
                            e.preventDefault();
                            createForm.post(route('orders.store'), {
                                preserveScroll: true,
                                onSuccess: () => createForm.setData({ ...DEFAULT_FORM }),
                            });
                        }}
                        submitLabel="Save"
                    />
                </section>

                <section className="app-widget p-4">
                    <h2 className="text-base font-semibold text-slate-900">Orders list</h2>
                    <div className="mt-4 overflow-x-auto rounded-lg border border-slate-200 bg-white">
                        <table className="min-w-full divide-y divide-slate-200 text-sm">
                            <thead className="bg-slate-50">
                                <tr>
                                    <Th>Title</Th><Th>Customer</Th><Th>Service</Th><Th>Status</Th><Th>Scheduled</Th><Th>Staff</Th><Th>Actions</Th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {orders.map((order) => (
                                    <tr key={order.id}>
                                        <Td>{order.title}</Td>
                                        <Td>{order.customer_name || '—'}</Td>
                                        <Td>{order.service_name || '—'}</Td>
                                        <Td>{order.status}</Td>
                                        <Td>{order.scheduled_at ? formatDate(order.scheduled_at) : '—'}</Td>
                                        <Td>{(order.staff_names ?? []).join(', ') || '—'}</Td>
                                        <Td>
                                            <div className="flex flex-wrap gap-2">
                                                <button type="button" className="text-indigo-700" onClick={() => startEdit(order)}>Edit</button>
                                                <button
                                                    type="button"
                                                    className="text-red-700"
                                                    onClick={() => {
                                                        if (window.confirm('Delete order?')) {
                                                            router.delete(route('orders.destroy', order.id), {
                                                                preserveScroll: true,
                                                            });
                                                        }
                                                    }}
                                                >
                                                    Delete
                                                </button>
                                                <button
                                                    type="button"
                                                    className="text-slate-700"
                                                    onClick={() =>
                                                        router.patch(route('orders.status', order.id), { status: 'in_progress' })
                                                    }
                                                >
                                                    Mark in progress
                                                </button>
                                                <button
                                                    type="button"
                                                    className="text-emerald-700"
                                                    onClick={() =>
                                                        router.patch(route('orders.status', order.id), { status: 'completed' })
                                                    }
                                                >
                                                    Mark completed
                                                </button>
                                            </div>
                                        </Td>
                                    </tr>
                                ))}
                                {orders.length === 0 && (
                                    <tr>
                                        <td className="px-4 py-5 text-slate-500" colSpan={7}>No orders yet.</td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            {editingOrder && (
                <Modal title="Edit order" onClose={() => setEditingOrder(null)}>
                    <OrderForm
                        form={editForm}
                        customers={customers}
                        services={services}
                        staff={staff}
                        statuses={statuses}
                        onToggleStaff={(id) => toggleStaff(editForm, id)}
                        onSubmit={(e) => {
                            e.preventDefault();
                            editForm.patch(route('orders.update', editingOrder.id), {
                                preserveScroll: true,
                                onSuccess: () => setEditingOrder(null),
                            });
                        }}
                        submitLabel="Update"
                    />
                    <div className="mt-4 flex justify-end">
                        <button
                            type="button"
                            className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700"
                            onClick={() =>
                                router.patch(route('orders.assign', editingOrder.id), {
                                    staff_ids: editForm.data.staff_ids,
                                })
                            }
                        >
                            Save staff assignment
                        </button>
                    </div>
                </Modal>
            )}
        </AdminLayout>
    );
}

function OrderForm({ form, customers, services, staff, statuses, onToggleStaff, onSubmit, submitLabel }) {
    return (
        <form className="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2" onSubmit={onSubmit}>
            <SelectField
                label="Customer"
                value={form.data.customer_id}
                onChange={(v) => form.setData('customer_id', v)}
                options={customers.map((item) => ({ value: item.id, label: item.name }))}
                allowEmpty
            />
            <SelectField
                label="Service"
                value={form.data.service_id}
                onChange={(v) => form.setData('service_id', v)}
                options={services.map((item) => ({ value: item.id, label: item.name }))}
                allowEmpty
            />
            <Field label="Title" value={form.data.title} onChange={(v) => form.setData('title', v)} error={form.errors.title} />
            <SelectField
                label="Status"
                value={form.data.status}
                onChange={(v) => form.setData('status', v)}
                options={statuses.map((status) => ({ value: status, label: status }))}
            />
            <Field label="Scheduled at" type="datetime-local" value={form.data.scheduled_at} onChange={(v) => form.setData('scheduled_at', v)} error={form.errors.scheduled_at} />
            <Field label="Completed at" type="datetime-local" value={form.data.completed_at} onChange={(v) => form.setData('completed_at', v)} error={form.errors.completed_at} />
            <Field label="Total" type="number" value={form.data.total} onChange={(v) => form.setData('total', v)} error={form.errors.total} />
            <div className="md:col-span-2">
                <label className="mb-1 block text-sm font-medium text-slate-600">Description</label>
                <textarea
                    rows={3}
                    value={form.data.description}
                    onChange={(e) => form.setData('description', e.target.value)}
                    className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                />
            </div>
            <div className="md:col-span-2">
                <label className="mb-1 block text-sm font-medium text-slate-600">Notes</label>
                <textarea
                    rows={3}
                    value={form.data.notes}
                    onChange={(e) => form.setData('notes', e.target.value)}
                    className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                />
            </div>
            <div className="md:col-span-2">
                <label className="mb-2 block text-sm font-medium text-slate-600">Assigned staff</label>
                <div className="grid grid-cols-1 gap-2 md:grid-cols-3">
                    {staff.map((member) => (
                        <label key={member.id} className="flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700">
                            <input
                                type="checkbox"
                                checked={(form.data.staff_ids ?? []).includes(member.id)}
                                onChange={() => onToggleStaff(member.id)}
                            />
                            <span>{member.name}</span>
                        </label>
                    ))}
                </div>
            </div>
            <div className="md:col-span-2 flex justify-end">
                <button type="submit" className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">{submitLabel}</button>
            </div>
        </form>
    );
}

function Modal({ title, children, onClose }) {
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4">
            <div className="w-full max-w-4xl rounded-xl border border-slate-200 bg-white p-6 shadow-xl">
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

function SelectField({ label, value, onChange, options, allowEmpty = false }) {
    return (
        <div>
            <label className="mb-1 block text-sm font-medium text-slate-600">{label}</label>
            <select
                value={value}
                onChange={(e) => onChange(e.target.value)}
                className="h-10 w-full rounded-lg border border-slate-300 px-3 text-sm"
            >
                {allowEmpty && <option value="">—</option>}
                {options.map((option) => (
                    <option key={String(option.value)} value={option.value}>{option.label}</option>
                ))}
            </select>
        </div>
    );
}

function toDatetimeInput(value) {
    if (!value) {
        return '';
    }

    try {
        return new Date(value).toISOString().slice(0, 16);
    } catch {
        return '';
    }
}

function formatDate(value) {
    try {
        return new Date(value).toLocaleString();
    } catch {
        return value;
    }
}
