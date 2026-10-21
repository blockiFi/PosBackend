<?php

namespace App\Http\Middleware;

use App\Support\StaffStockChange;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stock and product changes by staff in the configured roles must carry the
 * person's own PIN in the X-Staff-Pin header.
 */
class RequireStaffPin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! StaffStockChange::isStockWrite($request)) {
            return $next($request);
        }

        $role = StaffStockChange::staffRole($request);
        if (! $role || ! in_array($role, config('staff_pin.roles', []), true)) {
            return $next($request);
        }

        $user = $request->user();
        if (blank($user->pin_code)) {
            return $this->reject('pin_not_set', 'You have no PIN yet. Ask the owner to set your PIN.');
        }

        if (StaffStockChange::sentPin($request) === '') {
            return $this->reject('pin_required', 'Enter your PIN.');
        }

        $key = 'staff-pin:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, (int) config('staff_pin.max_attempts', 5))) {
            return response()->json([
                'message' => 'Too many wrong PINs. Wait a minute and try again.',
                'code' => 'pin_locked',
            ], 429);
        }

        if (! StaffStockChange::pinMatches($request)) {
            RateLimiter::hit($key, 60);

            return $this->reject('pin_wrong', 'Wrong PIN.');
        }

        RateLimiter::clear($key);

        return $next($request);
    }

    private function reject(string $code, string $message): Response
    {
        return response()->json(['message' => $message, 'code' => $code], 428);
    }
}
