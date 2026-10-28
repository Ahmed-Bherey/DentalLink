<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STAGE 7 — the canonical product catalogue.
 *
 * Until now `products` carried three unrelated responsibilities at once:
 *
 *   1. canonical identity      (name + category)
 *   2. a supplier's offer      (user_id = supplier, one mutable price)
 *   3. a doctor's inventory    (user_id = doctor, a COPY created on delivery
 *                               and matched back by NAME STRING)
 *
 * Because identity was duplicated per owner, "Dental Composite A" from two
 * suppliers was two unrelated rows, and the doctor's copy was a third. Nothing
 * tied them together, so the catalogue could not be searched, compared or
 * managed centrally, and the delivery path matched inventory on `name` — which
 * silently merges two genuinely different products that happen to share a name,
 * and silently splits one product whose name was edited.
 *
 * This table takes responsibility (1). `products` keeps (2) and (3) and gains a
 * foreign key to here, so every holding of a product points at one identity.
 *
 * Deliberately NOT here: price. A canonical product has no price — only a
 * supplier's offer of it does. Keeping price out is what makes the same product
 * comparable across suppliers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_masters', function (Blueprint $table) {
            $table->id();

            // Identity. Unique so the catalogue cannot accumulate the duplicates
            // it exists to prevent; the application also validates this so the
            // user gets a field error rather than a constraint violation.
            $table->string('name')->unique();

            $table->unsignedBigInteger('category_id')->nullable();
            $table->longText('desc')->nullable();
            $table->string('img')->nullable();

            // Free text, matching how `unit` was referenced (but never stored)
            // by the old delivery code. Kept optional.
            $table->string('unit')->nullable();

            // Stage 8 owns the broader soft-delete/archive strategy. A canonical
            // product referenced by order history must never be hard-deleted, so
            // withdrawing one from circulation needs SOME mechanism now; this is
            // the minimum that makes the catalogue usable without pre-empting
            // that design. It hides a product from selection, nothing more.
            $table->boolean('is_active')->default(true);

            // Who created it, for provenance. Nullable and ON DELETE SET NULL:
            // the catalogue outlives the administrator who populated it.
            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();

            $table->foreign('category_id')->references('id')->on('categories')
                ->onUpdate('cascade')->onDelete('restrict');

            $table->foreign('created_by')->references('id')->on('users')
                ->onUpdate('cascade')->onDelete('set null');

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_masters');
    }
};
