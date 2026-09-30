<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SessionRevocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CompanyProfileController extends Controller
{
    public function update(Request $request, SessionRevocationService $sessions): JsonResponse
    {
        $data = Validator::make($request->all(), [
            'first_name' => ['required', 'string', 'max:120'],
            'second_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,'.$request->user()->id],
            'current_password' => ['nullable', 'string'],
            'password' => ['nullable', 'confirmed', 'string', 'min:8'],
        ])->validate();
        if (! empty($data['password']) && (! isset($data['current_password']) || ! Hash::check($data['current_password'], $request->user()->password))) {
            return response()->json(['message' => 'The current password is invalid.'], 422);
        }

        $user = $request->user();
        $user->forceFill([
            'first_name' => $data['first_name'],
            'second_name' => $data['second_name'],
            'name' => trim($data['first_name'].' '.$data['second_name']),
            'email' => $data['email'],
            ...(! empty($data['password']) ? ['password' => Hash::make($data['password'])] : []),
        ])->save();
        if (! empty($data['password'])) {
            $user->tokens()->delete();
            $sessions->revoke($user);
        }

        return response()->json(['data' => $user->only(['id', 'name', 'first_name', 'second_name', 'email'])]);
    }

    public function updatePassword(Request $request, SessionRevocationService $sessions): JsonResponse
    {
        $data = Validator::make($request->all(), [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', 'string', 'min:8'],
        ])->validate();

        if (! Hash::check($data['current_password'], $request->user()->password)) {
            return response()->json(['message' => 'The current password is invalid.'], 422);
        }

        $request->user()->forceFill(['password' => Hash::make($data['password'])])->save();
        $request->user()->tokens()->delete();
        $sessions->revoke($request->user());

        return response()->json(['message' => 'Password updated.']);
    }
}
