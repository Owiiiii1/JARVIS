<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Service;
use App\Models\Staff;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OrdersController extends Controller
{
    public function index(): Response
    {
        $orders = Order::query()
            ->with(['customer:id,name', 'service:id,name', 'staff:id,name'])
            ->orderByDesc('created_at')
            ->get()
            ->map(static fn (Order $order): array => [
                'id' => $order->id,
                'customer_id' => $order->customer_id,
                'service_id' => $order->service_id,
                'title' => $order->title,
                'description' => $order->description,
                'status' => $order->status,
                'scheduled_at' => optional($order->scheduled_at)->toIso8601String(),
                'completed_at' => optional($order->completed_at)->toIso8601String(),
                'total' => $order->total,
                'notes' => $order->notes,
                'customer_name' => $order->customer?->name,
                'service_name' => $order->service?->name,
                'staff_ids' => $order->staff->pluck('id')->all(),
                'staff_names' => $order->staff->pluck('name')->all(),
                'created_at' => optional($order->created_at)->toIso8601String(),
            ])
            ->all();

        $customers = Customer::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->all();

        $services = Service::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->all();

        $staff = Staff::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->all();

        return Inertia::render('Orders/Index', [
            'orders' => $orders,
            'customers' => $customers,
            'services' => $services,
            'staff' => $staff,
            'statuses' => Order::statuses(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateOrder($request);

        $staffIds = $validated['staff_ids'] ?? [];
        unset($validated['staff_ids']);

        $order = Order::query()->create($validated);
        $order->staff()->sync($staffIds);

        return redirect()->route('orders.index');
    }

    public function update(Request $request, Order $order): RedirectResponse
    {
        $validated = $this->validateOrder($request);

        $staffIds = $validated['staff_ids'] ?? [];
        unset($validated['staff_ids']);

        $order->update($validated);
        $order->staff()->sync($staffIds);

        return redirect()->route('orders.index');
    }

    public function destroy(Order $order): RedirectResponse
    {
        $order->delete();

        return redirect()->route('orders.index');
    }

    public function updateStatus(Request $request, Order $order): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(Order::statuses())],
        ]);

        $order->status = $validated['status'];
        if ($validated['status'] === Order::STATUS_COMPLETED) {
            $order->completed_at = now();
        } elseif ($order->completed_at !== null) {
            $order->completed_at = null;
        }
        $order->save();

        return redirect()->route('orders.index');
    }

    public function assign(Request $request, Order $order): RedirectResponse
    {
        $validated = $request->validate([
            'staff_ids' => ['array'],
            'staff_ids.*' => ['integer', 'exists:staff,id'],
        ]);

        $order->staff()->sync($validated['staff_ids'] ?? []);

        return redirect()->route('orders.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateOrder(Request $request): array
    {
        $data = $request->all();
        foreach ([
            'customer_id',
            'service_id',
            'description',
            'scheduled_at',
            'completed_at',
            'total',
            'notes',
        ] as $field) {
            if (array_key_exists($field, $data) && $data[$field] === '') {
                $data[$field] = null;
            }
        }

        if (array_key_exists('staff_ids', $data) && is_array($data['staff_ids'])) {
            $data['staff_ids'] = array_values(array_filter(
                array_map(static fn ($id) => is_numeric($id) ? (int) $id : null, $data['staff_ids']),
                static fn ($id) => $id !== null
            ));
        }

        return validator($data, [
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'status' => ['required', Rule::in(Order::statuses())],
            'scheduled_at' => ['nullable', 'date'],
            'completed_at' => ['nullable', 'date'],
            'total' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'staff_ids' => ['array'],
            'staff_ids.*' => ['integer', 'exists:staff,id'],
        ])->validate();
    }
}
