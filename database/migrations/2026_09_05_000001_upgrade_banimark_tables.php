<?php

use Banimark\Licensing\Master;
use Banimark\Storage\Schema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every release that touches the schema needs its OWN migration file: Laravel
 * records a migration once and never runs it again, so the original
 * create_banimark_tables migration cannot deliver later tables or columns to
 * an install that already ran it. Schema::create() is idempotent, so this is
 * safe on a fresh install too (it simply finds everything present).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::ensureCurrent(DB::connection()->getPdo(), Master::PACKAGE_VERSION);
    }

    public function down(): void
    {
        // additive only - dropping a customer's conversation data on rollback
        // would be far worse than leaving unused columns behind
    }
};
