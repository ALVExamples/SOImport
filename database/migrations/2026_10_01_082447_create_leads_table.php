<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('source_row');
            $table->string('external_id', 64)->nullable()->index();
            $table->dateTime('created_at')->nullable();
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('source', 100)->nullable();
            $table->string('utm_campaign', 100)->nullable();
            $table->string('product', 150)->nullable();
            $table->decimal('budget_uah', 14, 2)->nullable();
            $table->string('status', 32)->nullable();
            $table->string('manager', 100)->nullable();
            $table->text('comment')->nullable();
            $table->dateTime('next_contact_at')->nullable();
            $table->json('issues')->nullable();

            $table->unique(['import_id', 'source_row']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
