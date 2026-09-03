<?php

namespace App\Http\Controllers;

use App\Models\Staff;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StaffController extends Controller
{
    public function index(): Response
    {
        $staff = Staff::query()
            ->orderBy('name')
            ->get()
            ->map(static fn (Staff $member): array => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'phone' => $member->phone,
                'role' => $member->role,
                'is_active' => (bool) $member->is_active,
                'notes' => $member->notes,
                'created_at' => optional($member->created_at)->toIso8601String(),
            ])
            ->all();

        return Inertia::render('Staff/Index', [
            'staff' => $staff,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = validator($this->normalize($request->all()), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'role' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ])->validate();

        $validated['is_active'] = (bool) ($validated['is_active'] ?? true);

        Staff::query()->create($validated);

        return redirect()->route('staff.index');
    }

    public function update(Request $request, Staff $staff): RedirectResponse
    {
        $validated = validator($this->normalize($request->all()), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'role' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ])->validate();

        $validated['is_active'] = (bool) ($validated['is_active'] ?? false);

        $staff->update($validated);

        return redirect()->route('staff.index');
    }

    public function destroy(Staff $staff): RedirectResponse
    {
        $staff->delete();

        return redirect()->route('staff.index');
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function normalize(array $input): array
    {
        foreach (['email', 'phone', 'role', 'notes'] as $field) {
            if (array_key_exists($field, $input) && $input[$field] === '') {
                $input[$field] = null;
            }
        }

        return $input;
    }
}
