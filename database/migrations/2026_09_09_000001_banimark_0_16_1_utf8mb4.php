<?php

use Banimark\Licensing\Master;
use Banimark\Storage\Schema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 0.16.1: emoji. Tables created before this release took the database's own
 * default charset, which on MySQL is usually still utf8mb3 - three bytes per
 * character, so a 4-byte emoji was refused outright and the visitor met a 500.
 * ensureCurrent() re-runs the idempotent schema, which now converts them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::ensureCurrent(DB::connection()->getPdo(), Master::PACKAGE_VERSION);
    }

    public function down(): void
    {
        // additive only
    }
};
