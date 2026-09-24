<?php

use Banimark\Licensing\Master;
use Banimark\Storage\Schema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** 0.24: honest update checks, staged updates, the panel-wide notice. No new columns. */
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
