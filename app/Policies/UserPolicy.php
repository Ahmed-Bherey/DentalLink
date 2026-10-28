<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\ChecksOwnership;
use Illuminate\Auth\Access\Response;

/**
 * Governs the user directory exposed by the report endpoints.
 *
 * These endpoints return name, phone, phone2, address and city — real personal
 * data — so they are the highest-value read surface in the application.
 *
 * CURRENT RULE (matches Stage 1 containment):
 *   a supplier may browse doctors, a doctor may browse suppliers, and a detail
 *   lookup must resolve a user of the expected counterparty role.
 *
 * KNOWN GAP, deferred: this still permits browsing ALL counterparties, not only
 * those the caller has actually transacted with. Narrowing it requires deciding
 * whether the marketplace is meant to be a discoverable directory — a product
 * question, not an authorization bug. See the Stage 4 report.
 */
class UserPolicy
{
    use ChecksOwnership;

    /** A user may always read their own profile. */
    public function view(User $user, User $target): Response
    {
        return (int) $user->id === (int) $target->id
            ? Response::allow()
            : $this->denyAsMissing();
    }

    /** A user may only update their own profile. */
    public function update(User $user, User $target): Response
    {
        return (int) $user->id === (int) $target->id
            ? Response::allow()
            : $this->denyAsMissing();
    }

    /** Suppliers browse the doctor directory. */
    public function viewDoctorDirectory(User $user): Response
    {
        return $user->isSupplier()
            ? Response::allow()
            : $this->denyForbidden('عفوا، ليس لديك صلاحية الاطلاع على قائمة الأطباء.');
    }

    /** Doctors browse the supplier directory. */
    public function viewSupplierDirectory(User $user): Response
    {
        return $user->isDoctor()
            ? Response::allow()
            : $this->denyForbidden('عفوا، ليس لديك صلاحية الاطلاع على قائمة الموردين.');
    }

    /**
     * Reading one counterparty's detail record.
     *
     * Denies as NOT FOUND so the endpoint cannot be walked to enumerate users of
     * other roles — that was the Stage 0 full-directory disclosure.
     */
    public function viewCounterparty(User $user, User $target): Response
    {
        if ($user->isSupplier() && $target->isDoctor()) {
            return Response::allow();
        }

        if ($user->isDoctor() && $target->isSupplier()) {
            return Response::allow();
        }

        return $this->denyAsMissing();
    }
}
