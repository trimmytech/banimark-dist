<?php

use Banimark\Licensing\Master;
use Banimark\Storage\Schema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** 0.16: who replied (messages.agent_id), staff presence, flood counters. */
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
