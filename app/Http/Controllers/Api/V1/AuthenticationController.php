<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AuthenticationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthenticationController extends Controller
{
    public function login(Request $request, AuthenticationService $authentication): JsonResponse
    {
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $user = $authentication->authenticate(
            $credentials['email'], $credentials['password'], 'company',
            $request->ip(), $request->userAgent(),
        );

        if (! $user || ! $user->company) {
            return response()->json(['message' => 'The supplied sign-in details are invalid.'], 401);
        }

        return response()->json([
            'token' => $user->createToken('api-v1')->plainTextToken,
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'company_id' => $user->company_id],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(['message' => 'Signed out.']);
    }
}
