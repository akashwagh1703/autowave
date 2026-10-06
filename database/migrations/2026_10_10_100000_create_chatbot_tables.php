<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp assistant (ADR-021).
 *
 * - outbound_messages.interactive: buttons, a list or an image sent with the text (provider-neutral).
 * - outbound_messages.assistant: sent by the assistant, not typed by a person or an automation.
 * - chatbot_sessions: where each conversation is in the assistant's menu, and when it is paused for a person.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbound_messages', function (Blueprint $table) {
            $table->jsonb('interactive')->nullable()->after('template');
            $table->boolean('assistant')->default(false)->after('simulated');
        });

        Schema::create('chatbot_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('conversation_id');
            $table->string('state', 40)->default('menu');
            $table->jsonb('data')->nullable();
            $table->unsignedSmallInteger('misses')->default(0);
            $table->timestamp('last_reply_at')->nullable();
            $table->timestamp('paused_until')->nullable();
            $table->timestamps();

            $table->foreign(['conversation_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('conversations')->cascadeOnDelete();

            $table->unique(['tenant_id', 'conversation_id']);
            $table->index('conversation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_sessions');

        Schema::table('outbound_messages', function (Blueprint $table) {
            $table->dropColumn(['interactive', 'assistant']);
        });
    }
};
