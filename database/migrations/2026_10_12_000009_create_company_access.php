<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Extra companies a user may work in, each with its own role there. A user still belongs to one home company, which
 * is where they sign in and what their account is attached to. A grant lets them switch into another company and act
 * with the granted role, nothing more. Only platform admins create or remove grants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_access', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $t->string('role', 30);
            $t->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['user_id', 'company_id']);
            $t->index('company_id');
        });
        DB::statement("ALTER TABLE company_access ADD CONSTRAINT valid_access_role CHECK (role IN ('company_admin','company_manager','company_technician'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('company_access');
    }
};
