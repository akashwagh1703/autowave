<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Messaging channels, conversations and templates (Phase 8, ADR-018).
 *
 * - messaging_channels: a tenant's connected WhatsApp number / Instagram account, credentials encrypted.
 * - conversations: one thread per (tenant, channel, contact handle).
 * - conversation_messages: the thread; inbound rows are idempotent on the provider message id.
 * - message_templates: WhatsApp templates synced from Meta.
 * - messaging_webhook_calls: verified webhook bodies, processed on the queue, pruned.
 * - outbound_messages: conversation link, template, quiet-hours schedule, delivery and read receipts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messaging_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 20);
            $table->string('status', 20)->default('disconnected');
            $table->string('external_id', 64)->nullable();
            $table->string('business_account_id', 64)->nullable();
            $table->string('display_name', 150)->nullable();
            $table->string('display_handle', 150)->nullable();
            $table->text('credentials')->nullable();
            $table->string('webhook_key', 64)->unique();
            $table->string('last_error', 500)->nullable();
            $table->foreignId('connected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_webhook_at')->nullable();
            $table->timestamps();

            $table->unique(['id', 'tenant_id']);
            $table->unique(['tenant_id', 'channel']);
            // One number / account can only feed one tenant.
            $table->unique(['channel', 'external_id']);
            $table->index('connected_by_user_id');
        });

        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 20);
            $table->string('contact_handle', 191);
            $table->string('contact_name', 150)->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('lead_id')->nullable();
            $table->unsignedBigInteger('assigned_tenant_user_id')->nullable();
            $table->string('status', 20)->default('open');
            $table->unsignedInteger('unread_count')->default(0);
            $table->timestamp('last_message_at')->nullable();
            $table->string('last_message_preview', 200)->nullable();
            $table->string('last_message_direction', 10)->nullable();
            $table->timestamp('last_inbound_at')->nullable();
            $table->timestamp('opted_out_at')->nullable();
            $table->timestamps();

            $table->foreign(['customer_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('customers')->cascadeOnDelete();
            $table->foreign(['lead_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('leads')->cascadeOnDelete();
            $table->foreign(['assigned_tenant_user_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('tenant_users');

            $table->unique(['id', 'tenant_id']);
            $table->unique(['tenant_id', 'channel', 'contact_handle']);
            $table->index(['tenant_id', 'status', 'last_message_at']);
            $table->index(['tenant_id', 'assigned_tenant_user_id']);
            $table->index('customer_id');
            $table->index('lead_id');
            $table->index('assigned_tenant_user_id');
        });

        // The unread badge: open conversations with unread messages, per tenant.
        DB::statement('CREATE INDEX conversations_unread_index ON conversations (tenant_id) WHERE unread_count > 0');

        Schema::table('outbound_messages', function (Blueprint $table) {
            $table->unsignedBigInteger('conversation_id')->nullable()->after('automation_run_id');
            $table->foreignId('sent_by_user_id')->nullable()->after('conversation_id')->constrained('users')->nullOnDelete();
            $table->jsonb('template')->nullable()->after('body');
            $table->timestamp('scheduled_for')->nullable()->after('queued_at');
            $table->timestamp('delivered_at')->nullable()->after('sent_at');
            $table->timestamp('read_at')->nullable()->after('delivered_at');

            $table->foreign(['conversation_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('conversations')->cascadeOnDelete();

            $table->unique(['id', 'tenant_id']);
            // Delivery receipts look messages up by the provider's id.
            $table->index(['tenant_id', 'provider_message_id']);
            $table->index('conversation_id');
            $table->index('sent_by_user_id');
        });

        Schema::create('conversation_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('conversation_id');
            $table->string('channel', 20);
            $table->string('direction', 10);
            $table->string('type', 20)->default('text');
            $table->text('body')->nullable();
            $table->string('provider_message_id', 191)->nullable();
            $table->unsignedBigInteger('outbound_message_id')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestamp('sent_at');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign(['conversation_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('conversations')->cascadeOnDelete();
            $table->foreign(['outbound_message_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('outbound_messages')->cascadeOnDelete();

            $table->index(['conversation_id', 'sent_at', 'id']);
            $table->index('tenant_id');
            $table->index('outbound_message_id');
        });

        // A webhook retry never stores the same inbound message twice.
        DB::statement('CREATE UNIQUE INDEX conversation_messages_provider_unique ON conversation_messages (tenant_id, channel, provider_message_id) WHERE provider_message_id IS NOT NULL');

        Schema::create('message_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 20)->default('whatsapp');
            $table->string('name', 512);
            $table->string('language', 20);
            $table->string('category', 30)->nullable();
            $table->string('status', 30);
            $table->text('body')->nullable();
            $table->unsignedSmallInteger('variables')->default(0);
            $table->string('provider_template_id', 64)->nullable();
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'channel', 'name', 'language']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('messaging_webhook_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('messaging_channel_id');
            $table->jsonb('payload');
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->foreign(['messaging_channel_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('messaging_channels')->cascadeOnDelete();

            $table->index(['status', 'updated_at']);
            $table->index(['tenant_id', 'created_at']);
            $table->index('messaging_channel_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messaging_webhook_calls');
        Schema::dropIfExists('message_templates');
        Schema::dropIfExists('conversation_messages');

        Schema::table('outbound_messages', function (Blueprint $table) {
            $table->dropForeign(['conversation_id', 'tenant_id']);
            $table->dropForeign(['sent_by_user_id']);
            $table->dropUnique(['id', 'tenant_id']);
            $table->dropIndex(['tenant_id', 'provider_message_id']);
            $table->dropIndex(['conversation_id']);
            $table->dropIndex(['sent_by_user_id']);
            $table->dropColumn(['conversation_id', 'sent_by_user_id', 'template', 'scheduled_for', 'delivered_at', 'read_at']);
        });

        Schema::dropIfExists('conversations');
        Schema::dropIfExists('messaging_channels');
    }
};
