<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * STAGE 6 — give a reversed payment somewhere to go, so history is not deleted.
 *
 * When a doctor approved a supplier's deletion request, PaymentService called
 * $paymentRecord->delete(). The row was gone: no record that the payment had
 * ever existed, that it had been reversed, who asked, or who approved. The
 * ledger was adjusted in the same breath, so the balance changed with nothing
 * left to explain why.
 *
 * Stage 5 established that historical financial records must not silently
 * disappear. This adds the smallest thing that satisfies that: one more value in
 * an enum that already models the payment lifecycle
 * (confirmed / pending / rejected / delete_pending).
 *
 * WHY NOT REUSE `rejected`
 *
 * `rejected` already means "the doctor declined an amendment the supplier
 * requested" — the payment survives at its original amount. A voided payment is
 * a different fact: the payment itself is withdrawn and no longer counts toward
 * the balance. Overloading one value would make the two indistinguishable in
 * exactly the records where the distinction matters.
 *
 * WHAT THIS IS NOT
 *
 * Not a reversal ledger. There is no contra-entry, no reversal document, no
 * audit trail of who voided what — those would be new financial concepts and
 * the brief rules them out. This is a status value. LedgerService counts only
 * `confirmed` payments, so a voided payment stops contributing to `paid`
 * automatically, and the existing listings already filter to `confirmed`, so a
 * voided payment disappears from the UI exactly as a deleted one did.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE payments MODIFY COLUMN status ENUM(
            'confirmed',
            'pending',
            'rejected',
            'delete_pending',
            'voided'
        ) NOT NULL DEFAULT 'confirmed'");
    }

    /**
     * Reverting removes the value, so any payment currently sitting in it would
     * become invalid. Refuse rather than let MariaDB coerce those rows to an
     * empty string, which is what a narrowing ENUM change does by default and
     * which would resurrect voided payments as unreadable records.
     *
     * @throws RuntimeException
     */
    public function down(): void
    {
        $voided = DB::table('payments')->where('status', 'voided')->count();

        if ($voided > 0) {
            throw new RuntimeException(
                "ROLLBACK ABORTED — {$voided} payment(s) currently have status 'voided'.\n\n"
                ."Removing the enum value would coerce them to an invalid empty status.\n"
                .'Decide what those payments should become before rolling back.'
            );
        }

        DB::statement("ALTER TABLE payments MODIFY COLUMN status ENUM(
            'confirmed',
            'pending',
            'rejected',
            'delete_pending'
        ) NOT NULL DEFAULT 'confirmed'");
    }
};
