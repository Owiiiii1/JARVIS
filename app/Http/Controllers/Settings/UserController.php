<?php

namespace App\Http\Controllers\Settings;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Users\AccessCodeGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function store(Request $request, AccessCodeGenerator $accessCodeGenerator): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        if (User::query()->where('role', UserRole::Owner)->exists() === false) {
            throw ValidationException::withMessages([
                'email' => 'Cannot create users before an owner exists.',
            ]);
        }

        User::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => UserRole::User,
            'access_code' => $accessCodeGenerator->generate(),
            'status' => UserStatus::Active,
            'timezone' => 'Europe/Rome',
        ]);

        return Redirect::route('settings.index', ['tab' => 'users']);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'password' => ['nullable', 'confirmed', Password::defaults()],
            'status' => ['required', Rule::enum(UserStatus::class)],
            'timezone' => ['required', 'timezone:all'],
        ]);

        if ($user->isOwner()) {
            unset($validated['status']);
            $validated['status'] = UserStatus::Active;
        }

        $user->fill([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'status' => $validated['status'],
            'timezone' => $validated['timezone'],
        ]);

        if (! empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }

        $user->save();

        return Redirect::route('settings.index', ['tab' => 'users']);
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ((int) $request->user()->id === (int) $user->id) {
            return Redirect::route('settings.index', ['tab' => 'users'])->withErrors([
                'user_delete' => 'You cannot delete your own account from this screen.',
            ]);
        }

        if ($user->isOwner()) {
            return Redirect::route('settings.index', ['tab' => 'users'])->withErrors([
                'user_delete' => 'The owner account cannot be deleted from this screen.',
            ]);
        }

        $user->delete();

        return Redirect::route('settings.index', ['tab' => 'users']);
    }
}
