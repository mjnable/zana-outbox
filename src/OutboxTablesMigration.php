<?php

declare(strict_types=1);

namespace Zana\Outbox;

use Zana\Database\Migration\Migration;
use Zana\Database\Schema\Blueprint;
use Zana\Database\Schema\Schema;

/**
 * The outbox and inbox tables. `php bin/console outbox:install` writes a
 * migration returning this class into database/migrations.
 */
final class OutboxTablesMigration extends Migration
{
    public function up(Schema $schema): void
    {
        $schema->create(Outbox::TABLE, static function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('type', 255);
            $t->text('payload');
            $t->integer('attempts')->default(0);
            $t->bigInteger('created_at');
            $t->bigInteger('available_at');
            $t->bigInteger('claimed_until')->default(0);
            $t->bigInteger('dispatched_at')->nullable();
            $t->bigInteger('failed_at')->nullable();
            $t->text('last_error')->nullable();
            $t->index(['dispatched_at', 'failed_at', 'available_at'], 'outbox_messages_pending_index');
        });

        $schema->create(Inbox::TABLE, static function (Blueprint $t): void {
            $t->string('consumer', 150);
            $t->string('message_id', 150);
            $t->bigInteger('handled_at');
            $t->unique(['consumer', 'message_id'], 'inbox_messages_consumer_message_unique');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists(Inbox::TABLE);
        $schema->dropIfExists(Outbox::TABLE);
    }
}
