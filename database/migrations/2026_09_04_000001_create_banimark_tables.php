<?php

use Banimark\Storage\Schema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema as LaravelSchema;

return new class extends Migration
{
    public function up(): void
    {
        // single source of truth: the same portable DDL the standalone web
        // installer and the test suite use - they can never disagree
        Schema::create(DB::connection()->getPdo());
    }

    public function down(): void
    {
        foreach (['settings', 'tools', 'rules', 'providers', 'messages', 'conversations'] as $t) {
            LaravelSchema::dropIfExists('banimark_'.$t);
        }
    }
};
