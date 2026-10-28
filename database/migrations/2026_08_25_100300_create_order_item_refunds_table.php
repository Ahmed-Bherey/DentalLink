<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * STAGE 7 — approved returns as first-class financial events.
 *
 * WHY A NEW RECORD RATHER THAN AN ADJUSTMENT
 *
 * An approved return moves money. Before this, the only trace was that
 * `order_expenses.total` had quietly become smaller: no record of what was
 * returned, how many, at what price, or when. The balance changed and nothing
 * explained why — the same failure Stage 6 fixed for deleted payments.
 *
 * Four representations were considered:
 *
 *   negative payment    Rejected. `payments` is the record of money the DOCTOR
 *                       sent the SUPPLIER. A refund travels the other way, and
 *                       Stage 6 added CHECK (amount >= 0) precisely so that
 *                       column cannot hold a negative. Overloading it would
 *                       corrupt the meaning of every payment query.
 *
 *   voided payment      Rejected. Voiding says "this payment never stood". A
 *                       refund says "it stood, and some of it came back". They
 *                       are different facts and a refund rarely matches one
 *                       payment's amount anyway.
 *
 *   adjust the ledger   Rejected. That is what happens today, and it is exactly
 *                       the unexplained-balance problem.
 *
 *   dedicated record    Chosen. Preserves payment history untouched, supports
 *                       any number of partial returns, is auditable, and carries
 *                       its own price snapshot.
 *
 * THE PRICE ON THIS ROW IS A SNAPSHOT OF A SNAPSHOT
 *
 * `unit_price` is copied from the order line's frozen unit price at the moment
 * of approval — never from the product's current price. A supplier who raises
 * a price after the sale must not thereby increase the refund they owe, and one
 * who lowers it must not reduce it.
 *
 * HOW IT REACHES THE BALANCE
 *
 * LedgerService derives, per (doctor, supplier) pair:
 *
 *     total  = charges - approved refunds
 *     paid   = max(0, payments - approved refunds)
 *
 * which produces the behaviour specified for this stage:
 *
 *     obligation 1000, paid 1000, refund 300  ->  total 700, paid 700, remaining 0
 *     obligation  100, paid   60, refund  30  ->  total  70, paid  30, remaining 40
 *
 * The refund reduces the debt AND the amount considered paid, because the doctor
 * has the money back. `remaining` is therefore untouched by a refund, which is
 * the arithmetic consequence of both sides moving together, not a special case.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_item_refunds', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('order_item_id');

            // Denormalised deliberately. The ledger is keyed on the pair, and
            // this lets refunds be summed for a pair without joining through
            // order_items -> orders and order_items -> products, one of which
            // (the product owner) is mutable.
            $table->unsignedBigInteger('doctor_id');
            $table->unsignedBigInteger('supplier_id');

            $table->integer('quantity');

            // Copied from order_items.unit_price at approval. Never re-read.
            $table->decimal('unit_price', 12, 2);
            $table->decimal('amount', 12, 2);

            // Who approved it. The supplier decides a return, so this is a
            // supplier id today; kept nullable so the row survives them.
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();

            // A refund is meaningless without the line it refunds, and the line
            // must not be removable while a refund references it.
            $table->foreign('order_item_id')->references('id')->on('order_items')
                ->onUpdate('cascade')->onDelete('restrict');

            // Financial history outlives both parties (Stage 5 precedent).
            $table->foreign('doctor_id')->references('id')->on('users')
                ->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('supplier_id')->references('id')->on('users')
                ->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('approved_by')->references('id')->on('users')
                ->onUpdate('cascade')->onDelete('set null');

            // The ledger's hot path: sum refunds for one pair.
            $table->index(['doctor_id', 'supplier_id'], 'order_item_refunds_pair_index');
        });

        DB::statement('ALTER TABLE order_item_refunds ADD CONSTRAINT chk_order_item_refunds_quantity_positive CHECK (quantity > 0)');
        DB::statement('ALTER TABLE order_item_refunds ADD CONSTRAINT chk_order_item_refunds_amount_non_negative CHECK (amount >= 0 AND unit_price >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_refunds');
    }
};
