<?php

namespace App\Http\Controllers;

use App\Models\Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ServicesController extends Controller
{
    public function index(): Response
    {
        $services = Service::query()
            ->orderBy('name')
            ->get()
            ->map(static fn (Service $service): array => [
                'id' => $service->id,
                'name' => $service->name,
                'description' => $service->description,
                'price' => $service->price,
                'duration_minutes' => $service->duration_minutes,
                'is_active' => (bool) $service->is_active,
                'created_at' => optional($service->created_at)->toIso8601String(),
            ])
            ->all();

        return Inertia::render('Services/Index', [
            'services' => $services,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = validator($this->normalize($request->all()), [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'duration_minutes' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ])->validate();

        $validated['is_active'] = (bool) ($validated['is_active'] ?? true);

        Service::query()->create($validated);

        return redirect()->route('services.index');
    }

    public function update(Request $request, Service $service): RedirectResponse
    {
        $validated = validator($this->normalize($request->all()), [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'duration_minutes' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ])->validate();

        $validated['is_active'] = (bool) ($validated['is_active'] ?? false);

        $service->update($validated);

        return redirect()->route('services.index');
    }

    public function destroy(Service $service): RedirectResponse
    {
        $service->delete();

        return redirect()->route('services.index');
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function normalize(array $input): array
    {
        foreach (['description', 'price', 'duration_minutes'] as $field) {
            if (array_key_exists($field, $input) && $input[$field] === '') {
                $input[$field] = null;
            }
        }

        return $input;
    }
}
