<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        // تحديث نوع العمود status باستخدام SQL مباشرة
        DB::statement("ALTER TABLE payments MODIFY COLUMN status ENUM(
            'confirmed',
            'pending',
            'rejected',
            'delete_pending',
        ) DEFAULT 'confirmed'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // في حال التراجع، نعيد enum كما كان في الأصل
        DB::statement("ALTER TABLE payments MODIFY COLUMN status ENUM(
            'confirmed',
            'pending',
            'rejected'
        ) DEFAULT 'confirmed'");
    }
};
