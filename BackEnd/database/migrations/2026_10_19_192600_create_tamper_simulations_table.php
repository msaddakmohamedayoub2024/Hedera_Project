<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tamper_simulations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->unique()->constrained('students')->cascadeOnDelete();
            $table->json('simulated_choices');
            $table->string('old_fingerprint', 64);
            $table->string('new_fingerprint', 64);
            $table->string('report_reference')->unique();
            $table->boolean('is_simulation')->default(true);
            $table->timestamp('alert_queued_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tamper_simulations');
    }
};
