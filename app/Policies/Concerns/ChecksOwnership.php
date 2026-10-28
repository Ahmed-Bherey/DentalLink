<?php

namespace App\Policies\Concerns;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;

/**
 * Shared ownership primitives for policies.
 *
 * Deliberately small. This is NOT a generic "can this user do anything" helper —
 * it only answers "does this column on this record point at this user?" and
 * standardises how a denial is shaped. Every actual authorization decision stays
 * in the policy method where it can be read and tested.
 */
trait ChecksOwnership
{
    use HandlesAuthorization;

    /**
     * Does the given foreign key on the record point at this user?
     *
     * Compared as integers because route parameters arrive as strings and a
     * loose comparison between "1" and 1 (or worse, between 0 and a non-numeric
     * string) is exactly how ownership checks get bypassed.
     */
    protected function owns(?User $user, $model, string $column): bool
    {
        if ($user === null || $model === null) {
            return false;
        }

        $ownerId = $model->{$column} ?? null;

        if ($ownerId === null) {
            // An unowned record is never "owned by" anyone. Fail closed.
            return false;
        }

        return (int) $ownerId === (int) $user->id;
    }

    /**
     * Is the user either party on a two-sided record (doctor <-> supplier)?
     */
    protected function isPartyTo(?User $user, $model): bool
    {
        return $this->owns($user, $model, 'doctor_id')
            || $this->owns($user, $model, 'supplier_id');
    }

    /**
     * Denial that hides the existence of the record (renders 404).
     *
     * Used for resources where a non-owner must not be able to tell "not yours"
     * from "does not exist" — otherwise the endpoint becomes an enumeration
     * oracle. This preserves the status codes chosen in Stage 1.
     */
    protected function denyAsMissing(): Response
    {
        return Response::denyAsNotFound(__('Resource not found.'));
    }

    /**
     * Denial that acknowledges the record but refuses the action (renders 403).
     *
     * Used where the counterparty already knows the record exists — both sides
     * of an order or a payment can see it, so hiding it would be pointless.
     */
    protected function denyForbidden(string $message = 'عفوا، ليس لديك صلاحية لتنفيذ هذا الإجراء.'): Response
    {
        return Response::deny($message);
    }
}
