<?php

use Banimark\Licensing\Master;
use Banimark\Storage\Schema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 0.14: staff invitations (status, invite_token, invited_at, activated_at) and
 * per-staff permissions. One migration per release - Laravel never re-runs a
 * recorded one, so this is the only way an existing install gets the columns.
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
