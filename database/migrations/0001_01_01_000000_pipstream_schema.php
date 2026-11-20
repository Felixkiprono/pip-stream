<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The shared PipStream schema (Laravel + Python engine). The SQL lives in
 * database/schema/pipstream.sql so the engine's tests can apply exactly the same file.
 * Later changes are normal Laravel migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('schema/pipstream.sql')));
    }

    public function down(): void
    {
        DB::unprepared('drop schema public cascade; create schema public;');
    }
};
