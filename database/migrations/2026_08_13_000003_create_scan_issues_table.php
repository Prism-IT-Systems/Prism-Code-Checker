<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scan_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scan_id')->constrained()->cascadeOnDelete();
            $table->string('file');
            $table->unsignedInteger('line')->nullable();
            $table->unsignedInteger('column')->nullable();
            $table->string('severity');
            $table->string('tool');
            $table->string('rule')->nullable();
            $table->text('message');
            $table->boolean('fixable')->default(false);
            $table->timestamps();

            $table->index(['scan_id', 'severity']);
            $table->index(['scan_id', 'tool']);
            $table->index(['scan_id', 'file']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_issues');
    }
};
