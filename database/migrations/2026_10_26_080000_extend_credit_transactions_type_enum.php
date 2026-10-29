<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE credit_transactions DROP CONSTRAINT IF EXISTS credit_transactions_type_check');
            DB::statement("ALTER TABLE credit_transactions ADD CONSTRAINT credit_transactions_type_check CHECK (type IN ('purchase', 'subscription', 'usage', 'refund', 'bonus', 'admin_adjustment'))");
        } elseif ($driver === 'sqlite') {
            Schema::table('credit_transactions', function (Blueprint $table) {
                $table->string('type', 50)->change();
            });
        } elseif ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement("ALTER TABLE credit_transactions MODIFY COLUMN type ENUM('purchase', 'subscription', 'usage', 'refund', 'bonus', 'admin_adjustment') NOT NULL");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('credit_transactions')->where('type', 'admin_adjustment')->exists()) {
            throw new RuntimeException('Cannot rollback migration: credit_transactions contains admin_adjustment records.');
        }

        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE credit_transactions DROP CONSTRAINT IF EXISTS credit_transactions_type_check');
            DB::statement("ALTER TABLE credit_transactions ADD CONSTRAINT credit_transactions_type_check CHECK (type IN ('purchase', 'subscription', 'usage', 'refund', 'bonus'))");
        } elseif ($driver === 'sqlite') {
            Schema::table('credit_transactions', function (Blueprint $table) {
                $table->enum('type', ['purchase', 'subscription', 'usage', 'refund', 'bonus'])->change();
            });
        } elseif ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement("ALTER TABLE credit_transactions MODIFY COLUMN type ENUM('purchase', 'subscription', 'usage', 'refund', 'bonus') NOT NULL");
        }
    }
};
