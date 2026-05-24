<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAiHistoryTables extends Migration
{
    public function up()
    {
        Schema::create('ai_import_batches', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('source', 32)->nullable()->index();
            $table->string('file_path', 1024);
            $table->string('status', 32)->default('running')->index();
            $table->unsignedInteger('session_count')->default(0);
            $table->unsignedInteger('message_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->json('options')->nullable();
            $table->longText('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_sessions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('import_batch_id')->nullable()->index();
            $table->string('source', 32)->index();
            $table->string('external_id', 191);
            $table->string('title', 512)->nullable();
            $table->string('workspace_path', 1024)->nullable();
            $table->string('model', 128)->nullable()->index();
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('message_count')->default(0);
            $table->string('source_path', 1024)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['source', 'external_id']);
            $table->foreign('import_batch_id')->references('id')->on('ai_import_batches')->nullOnDelete();
        });

        Schema::create('ai_messages', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('ai_session_id')->index();
            $table->unsignedInteger('seq');
            $table->string('role', 32)->nullable()->index();
            $table->string('type', 64)->nullable()->index();
            $table->timestamp('occurred_at')->nullable()->index();
            $table->longText('content')->nullable();
            $table->string('tool_name', 128)->nullable()->index();
            $table->json('metadata')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->unique(['ai_session_id', 'seq']);
            $table->foreign('ai_session_id')->references('id')->on('ai_sessions')->cascadeOnDelete();
        });
    }

    public function down()
    {
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_sessions');
        Schema::dropIfExists('ai_import_batches');
    }
}
