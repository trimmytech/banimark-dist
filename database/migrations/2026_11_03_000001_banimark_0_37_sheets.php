<?php

use Banimark\Licensing\Master;
use Banimark\Storage\Schema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** 0.37: spreadsheet tools - the uploaded CSV behind one (banimark_sheets). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::ensureCurrent(DB::connection()->getPdo(), Master::PACKAGE_VERSION);
        Schema::upgrade(DB::connection()->getPdo()); // idempotent; ensureCurrent is a no-op until the version moves
    }

    public function down(): void
    {
        // additive only
    }
};
