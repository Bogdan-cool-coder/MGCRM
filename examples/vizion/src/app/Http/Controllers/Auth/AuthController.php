<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        return response()->json([
            'user' => $user->load('company', 'activeCompany'),
            'token' => $user->createToken('api')->plainTextToken,
        ]);
    }

    public function iframeAuth(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
        ]);

        $user = User::where('iframe_token', $request->token)->first();

        if (! $user || $user->role === 'superadmin') {
            throw ValidationException::withMessages([
                'token' => [__('auth.failed')],
            ]);
        }

        return response()->json([
            'user' => $user->load('company', 'activeCompany'),
            'token' => $user->createToken('iframe')->plainTextToken,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => __('auth.logged_out')]);
    }
}
