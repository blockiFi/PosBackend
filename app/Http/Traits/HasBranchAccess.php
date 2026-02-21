<?php

namespace App\Http\Traits;

use App\Models\Branch;
use Illuminate\Support\Collection;

trait HasBranchAccess
{
    /**
     * Check if a user has access to a specific branch within a business.
     *
     * Logic:
     * 1. Validates the branch belongs to the business (prevents cross-business access).
     * 2. Checks if the user has a business-wide role (branch_id IS NULL in model_has_roles) -> full access.
     * 3. Checks if the user has a branch-specific role for the target branch.
     *
     * @param  mixed  $user
     */
    protected function userHasBranchAccess($user, int $businessId, int $branchId): bool
    {
        // Verify the branch belongs to the business
        $branchBelongsToBusiness = Branch::where('id', $branchId)
            ->where('business_id', $businessId)
            ->exists();

        if (! $branchBelongsToBusiness) {
            return false;
        }

        // Check for business-wide role (branch_id IS NULL)
        $hasBusinessWideRole = \DB::table('model_has_roles')
            ->where('model_type', get_class($user))
            ->where('model_id', $user->id)
            ->where('business_id', $businessId)
            ->whereNull('branch_id')
            ->exists();

        if ($hasBusinessWideRole) {
            return true;
        }

        // Check for branch-specific role
        $hasBranchRole = \DB::table('model_has_roles')
            ->where('model_type', get_class($user))
            ->where('model_id', $user->id)
            ->where('business_id', $businessId)
            ->where('branch_id', $branchId)
            ->exists();

        if ($hasBranchRole) {
            return true;
        }

        // If user has NO roles assigned for this business, check business membership
        // This handles owners/members who haven't been assigned explicit roles yet
        $hasAnyRole = \DB::table('model_has_roles')
            ->where('model_type', get_class($user))
            ->where('model_id', $user->id)
            ->where('business_id', $businessId)
            ->exists();

        if (! $hasAnyRole) {
            return $user->businesses()
                ->where('businesses.id', $businessId)
                ->wherePivot('is_active', true)
                ->exists();
        }

        return false;
    }

    /**
     * Get the branch IDs a user has access to within a business.
     *
     * Returns an empty collection if the user has business-wide access (no branch restrictions).
     * Returns a collection of branch IDs if the user has branch-specific roles only.
     *
     * @param  mixed  $user
     */
    protected function getPermittedBranches($user, int $businessId): Collection
    {
        // Check if user has a business-wide role (no branch_id)
        $hasBusinessWideRole = \DB::table('model_has_roles')
            ->where('model_type', get_class($user))
            ->where('model_id', $user->id)
            ->where('business_id', $businessId)
            ->whereNull('branch_id')
            ->exists();

        if ($hasBusinessWideRole) {
            // Empty collection signals "access to all branches"
            return collect([]);
        }

        // Return specific branch IDs the user has access to
        return $user->getBranchesInBusiness($businessId);
    }

    /**
     * Verify business membership for the authenticated user.
     *
     * @param  mixed  $user
     */
    protected function verifyBusinessMembership($user, int $businessId): bool
    {
        return $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->exists();
    }
}
