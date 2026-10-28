<?php

namespace App\Policies\Financial;

use App\Models\Financial\Receipt;
use App\Models\User;
use App\Policies\Concerns\ChecksOwnership;
use Illuminate\Auth\Access\Response;

/**
 * Ownership: receipts.user_id. A receipt is a private expense document,
 * so denials render 404 rather than confirming the id exists.
 */
class ReceiptPolicy
{
    use ChecksOwnership;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Receipt $receipt): Response
    {
        return $this->owns($user, $receipt, 'user_id')
            ? Response::allow()
            : $this->denyAsMissing();
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Receipt $receipt): Response
    {
        return $this->owns($user, $receipt, 'user_id')
            ? Response::allow()
            : $this->denyAsMissing();
    }

    /**
     * Bulk delete of the caller's OWN receipts for a given month.
     *
     * There is no target record to own, so this is a role-free capability; the
     * service scopes the delete to the caller's own rows. Kept as an explicit
     * ability so the endpoint is not silently unauthorized.
     */
    public function deleteAny(User $user): bool
    {
        return true;
    }

    public function delete(User $user, Receipt $receipt): Response
    {
        return $this->owns($user, $receipt, 'user_id')
            ? Response::allow()
            : $this->denyAsMissing();
    }
}
