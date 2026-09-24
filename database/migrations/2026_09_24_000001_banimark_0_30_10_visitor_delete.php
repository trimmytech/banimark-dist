<?php

use Banimark\Licensing\Master;
use Banimark\Storage\Schema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** 0.30.10: visitors can delete their conversation (soft) - conversations.visitor_deleted_at + kept. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::ensureCurrent(DB::connection()->getPdo(), Master::PACKAGE_VERSION);
        // ensureCurrent is a no-op when the version did not move; the columns
        // must still arrive, and upgrade() is idempotent
        Schema::upgrade(DB::connection()->getPdo());
    }

    public function down(): void
    {
        // additive only
    }
};
