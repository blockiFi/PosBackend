<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class StaffPinController extends Controller
{
    /**
     * Checks the signed-in person's own PIN before a stock change is sent,
     * so the PIN box can say "Wrong PIN" straight away.
     */
    public function check(Request $request): JsonResponse
    {
        $request->validate(['pin' => ['required', 'string', 'max:12']]);

        $user = $request->user();
        if (blank($user->pin_code)) {
            return response()->json([
                'message' => 'You have no PIN yet. Ask the owner to set your PIN.',
                'code' => 'pin_not_set',
            ], 422);
        }

        $key = 'staff-pin:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, (int) config('staff_pin.max_attempts', 5))) {
            return response()->json([
                'message' => 'Too many wrong PINs. Wait a minute and try again.',
                'code' => 'pin_locked',
            ], 429);
        }

        if (! hash_equals((string) $user->pin_code, trim((string) $request->input('pin')))) {
            RateLimiter::hit($key, 60);

            return response()->json(['message' => 'Wrong PIN.', 'code' => 'pin_wrong'], 422);
        }

        RateLimiter::clear($key);

        return response()->json(['ok' => true]);
    }
}
