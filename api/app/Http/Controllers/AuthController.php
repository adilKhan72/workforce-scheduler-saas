<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Models\StaffMember;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class AuthController
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if ($user === null || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }

        return response()->json([
            'token' => $user->createToken('dashboard', ['*'], now()->addHours(12))->plainTextToken,
            'user' => ['name' => $user->name, 'email' => $user->email, 'role' => $user->role],
            'tenant' => ['id' => $user->tenant->id, 'name' => $user->tenant->name],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['ok' => true]);
    }

    /** Who am I, and what can I see? Counts go through the tenant scope and RLS. */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user' => ['name' => $user->name, 'email' => $user->email, 'role' => $user->role],
            'tenant' => ['id' => $user->tenant->id, 'name' => $user->tenant->name],
            'counts' => ['locations' => Location::count(), 'staff' => StaffMember::count()],
        ]);
    }
}
