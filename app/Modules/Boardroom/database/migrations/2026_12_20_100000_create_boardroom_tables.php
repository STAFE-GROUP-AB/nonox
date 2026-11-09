<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Aktiebok och styrelserum (ADR-070, docs/specs/rules/aktiebok.md).
 *
 * The share register is an append-only event log (boardroom_share_events):
 * a trigger refuses UPDATE, DELETE and TRUNCATE, and every event carries
 * hash = sha256(previous_hash || canonical_json(event)) with the head kept
 * on boardroom_registers. Approved minutes are locked the same way: once
 * APPROVED, a row cannot be changed or removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boardroom_registers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained('teams')->cascadeOnDelete();
            $table->char('head_hash', 64)->nullable();
            $table->unsignedInteger('last_sequence')->default(0);
            $table->unsignedInteger('last_board_minutes_number')->default(0);
            // ABL 5 kap. 9 §: the board may authorise someone to keep the register (owner decision 2, L9).
            $table->date('firm_authorised_on')->nullable();
            $table->string('firm_authorisation_reference')->nullable();
            $table->foreignId('firm_authorised_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('boardroom_share_classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name');
            // Votes in tenths of a vote: 10 is one vote, 1 is a tenth (ABL 4 kap. 5 §).
            $table->unsignedInteger('votes_tenths')->default(10);
            $table->text('restriction_text')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });

        Schema::create('boardroom_shareholders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('name');
            $table->text('personal_number')->nullable();
            $table->string('org_number', 20)->nullable();
            $table->text('other_id')->nullable();
            $table->char('other_id_country', 2)->nullable();
            $table->string('address_line1');
            $table->string('address_line2')->nullable();
            $table->string('postal_code', 20);
            $table->string('city');
            $table->char('country', 2)->default('SE');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'name']);
        });

        Schema::create('boardroom_share_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('kind', 30);
            $table->date('effective_date');
            $table->dateTime('recorded_at');
            $table->date('decided_on')->nullable();
            $table->date('registered_on')->nullable();
            $table->unsignedBigInteger('share_from')->nullable();
            $table->unsignedBigInteger('share_to')->nullable();
            $table->foreignId('share_class_id')->nullable()->constrained('boardroom_share_classes')->restrictOnDelete();
            $table->foreignId('from_holder_id')->nullable()->constrained('boardroom_shareholders')->restrictOnDelete();
            $table->foreignId('to_holder_id')->nullable()->constrained('boardroom_shareholders')->restrictOnDelete();
            $table->text('holder_snapshot')->nullable();
            $table->bigInteger('consideration_ore')->nullable();
            $table->bigInteger('capital_change_ore')->nullable();
            $table->boolean('certificate_issued')->nullable();
            $table->unsignedInteger('ratio')->nullable();
            $table->foreignId('refers_to_event_id')->nullable()->constrained('boardroom_share_events')->restrictOnDelete();
            $table->text('note')->nullable();
            $table->string('document_reference')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->char('previous_hash', 64)->nullable();
            $table->char('hash', 64);
            $table->timestamp('created_at')->nullable();

            $table->unique(['company_id', 'sequence']);
            $table->index(['company_id', 'effective_date']);
            $table->index(['company_id', 'to_holder_id']);
            $table->index(['company_id', 'from_holder_id']);
        });

        Schema::create('boardroom_articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->date('adopted_on');
            $table->date('registered_on')->nullable();
            $table->string('seat')->nullable();
            $table->text('notice_method');
            $table->boolean('short_notice_clause')->default(false);
            $table->bigInteger('share_capital_min_ore')->nullable();
            $table->bigInteger('share_capital_max_ore')->nullable();
            $table->unsignedBigInteger('shares_min')->nullable();
            $table->unsignedBigInteger('shares_max')->nullable();
            $table->unsignedSmallInteger('board_min')->nullable();
            $table->unsignedSmallInteger('board_max')->nullable();
            $table->boolean('has_auditor')->default(false);
            $table->boolean('pre_emption_clause')->default(false);
            $table->boolean('consent_clause')->default(false);
            $table->boolean('redemption_clause')->default(false);
            $table->text('other_provisions')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'version']);
        });

        Schema::create('boardroom_meetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('title');
            $table->date('meeting_date');
            $table->string('start_time', 5)->nullable();
            $table->string('place')->nullable();
            $table->boolean('digital')->default(false);
            $table->foreignId('fiscal_year_id')->nullable()->constrained('fiscal_years')->nullOnDelete();
            $table->date('notice_sent_on')->nullable();
            $table->string('chair_name')->nullable();
            $table->string('minute_taker_name')->nullable();
            $table->json('adjusters')->nullable();
            $table->json('attendees')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'meeting_date']);
        });

        Schema::create('boardroom_agenda_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('meeting_id')->constrained('boardroom_meetings')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('kind', 30);
            $table->string('title');
            $table->text('proposal')->nullable();
            $table->text('decision')->nullable();
            $table->boolean('mandatory')->default(false);
            $table->string('reportable', 30)->nullable();
            $table->date('reported_on')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'meeting_id', 'position']);
        });

        Schema::create('boardroom_minutes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('meeting_id')->constrained('boardroom_meetings')->restrictOnDelete();
            $table->unsignedInteger('number')->nullable();
            $table->string('status', 20);
            $table->json('body');
            $table->json('checked_names')->nullable();
            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('pdf_path')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->foreignId('corrects_minutes_id')->nullable()->constrained('boardroom_minutes')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'meeting_id']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardroom_share_events_append_only() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'boardroom_share_events is append-only: % is not allowed', TG_OP
                    USING ERRCODE = 'insufficient_privilege';
            END;
            $$;

            CREATE TRIGGER boardroom_share_events_no_update_or_delete
                BEFORE UPDATE OR DELETE ON boardroom_share_events
                FOR EACH ROW EXECUTE FUNCTION boardroom_share_events_append_only();

            CREATE TRIGGER boardroom_share_events_no_truncate
                BEFORE TRUNCATE ON boardroom_share_events
                FOR EACH STATEMENT EXECUTE FUNCTION boardroom_share_events_append_only();

            CREATE OR REPLACE FUNCTION boardroom_minutes_locked() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.status = 'APPROVED' THEN
                    RAISE EXCEPTION 'approved minutes are locked: % is not allowed', TG_OP
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER boardroom_minutes_no_change_when_approved
                BEFORE UPDATE OR DELETE ON boardroom_minutes
                FOR EACH ROW EXECUTE FUNCTION boardroom_minutes_locked();

            CREATE OR REPLACE FUNCTION boardroom_minutes_no_truncate() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'boardroom_minutes cannot be truncated'
                    USING ERRCODE = 'insufficient_privilege';
            END;
            $$;

            CREATE TRIGGER boardroom_minutes_truncate
                BEFORE TRUNCATE ON boardroom_minutes
                FOR EACH STATEMENT EXECUTE FUNCTION boardroom_minutes_no_truncate();
            SQL);
    }
};
