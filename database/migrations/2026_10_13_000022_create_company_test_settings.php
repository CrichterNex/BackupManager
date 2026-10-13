<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which tests a company has. Two switches, set by different people:
 *  - available: the platform admin decides which tests a company is offered (a subset of all of them).
 *  - enabled: the company's own admin turns an available test off when they do not have the backup solution it needs.
 * A test is in use only when both are on. No row means both are on, so existing companies keep every test until someone changes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_test_settings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->string('test_type', 30);
            $t->boolean('available')->default(true);
            $t->boolean('enabled')->default(true);
            $t->foreignId('updated_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->unique(['company_id', 'test_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_test_settings');
    }
};
