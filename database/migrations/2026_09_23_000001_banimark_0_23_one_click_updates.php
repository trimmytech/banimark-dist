<?php

use Banimark\Licensing\Master;
use Banimark\Storage\Schema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 0.23: one-click updates from the admin panel.
 *
 * No new columns of its own - but ensureCurrent() is version-gated, and the
 * panel's "update your database" button reads the same schema_version row. A
 * migration per release is what keeps that number honest.
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
