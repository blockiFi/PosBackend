<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetBusinessContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        
        if ($user) {
            // Get business_id from header or query parameter
            $businessId = $request->header('X-Business-Id') ?? 
                         $request->input('business_id') ?? 
                         $request->input('current_business_id');
            
            if ($businessId) {
                // Verify user has access to this business
                $hasMembership = $user->businesses()
                    ->where('businesses.id', $businessId)
                    ->wherePivot('is_active', true)
                    ->exists();
                
                if (!$hasMembership) {
                    return response()->json([
                        'message' => 'You do not have access to this business',
                    ], 403);
                }
                
                // Set business context for permission checks
                app()->instance('current_business_id', (int) $businessId);
                $request->merge(['current_business_id' => (int) $businessId]);
                
                // Set the permissions team ID for Spatie permission package
                setPermissionsTeamId((int) $businessId);
            }
        }
        
        return $next($request);
    }
}
