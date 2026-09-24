<?php

use Banimark\Licensing\Master;
use Banimark\Storage\Schema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** 0.20: a tool can call the owner's own API (tools.kind, tools.config). */
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
