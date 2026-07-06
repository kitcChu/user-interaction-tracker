<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Honor a custom table name from the package config if set.
        $table = config('user-interaction-tracker.table', 'user_interactions');

        if (Schema::hasTable($table)) {
            return;
        }

        Schema::create($table, function (Blueprint $table) {
            $table->id();

            $table->string('event', 40)->comment('page_view|form_opened|field_focus|input_snapshot|form_abandoned|step_change|order_submitted');
            $table->string('session_id', 64)->index();

            $table->string('ip_address', 45)->nullable();
            $table->json('location')->nullable()->comment('GeoIP lookup result');
            $table->string('user_agent', 500)->nullable();
            $table->string('referrer', 500)->nullable();

            $table->unsignedBigInteger('page_id')->nullable()->index();
            $table->string('page_title', 255)->nullable();
            $table->string('url', 500)->nullable();
            $table->string('focus_field', 100)->nullable();
            $table->unsignedSmallInteger('current_step')->nullable();
            $table->json('fields')->nullable()->comment('Captured form input (pre-submission)');
            $table->string('note', 255)->nullable();

            $table->boolean('telegram_sent')->default(false);
            $table->string('telegram_error', 500)->nullable();
            $table->boolean('is_bot')->default(false);
            $table->timestamp('summarized_at')->nullable()->index();

            $table->timestamps();

            $table->index(['event', 'created_at']);
            $table->index(['session_id', 'event']);
        });
    }

    public function down(): void
    {
        $table = config('user-interaction-tracker.table', 'user_interactions');
        Schema::dropIfExists($table);
    }
};
