<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('intakes', function (Blueprint $table): void {
            $table->string('workflow_mode')->default('customer')->after('status');
            $table->boolean('customer_access_enabled')->default(true)->after('access_token');
        });

        Schema::table('intake_uploads', function (Blueprint $table): void {
            $table->string('analysis_path')->nullable()->after('path');
            $table->string('analysis_mime_type')->nullable()->after('analysis_path');
            $table->unsignedBigInteger('analysis_size_bytes')->nullable()->after('analysis_mime_type');
            $table->string('analysis_checksum')->nullable()->after('analysis_size_bytes');
        });

        Schema::table('intake_follow_up_rounds', function (Blueprint $table): void {
            $table->string('purpose')->default('follow_up')->after('round_number');
            $table->string('return_status')->nullable()->after('status');
        });

        Schema::create('dossier_subjects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('intake_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('dossier_subjects')->cascadeOnDelete();
            $table->string('type');
            $table->string('key');
            $table->string('label');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['intake_id', 'key']);
            $table->index(['company_id', 'type']);
        });

        Schema::create('dossier_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('intake_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dossier_subject_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->string('key');
            $table->json('value');
            $table->string('actor_type');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('source_type');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('method');
            $table->decimal('confidence', 4, 3)->nullable();
            $table->string('status');
            $table->timestamp('observed_at');
            $table->foreignId('superseded_by_id')->nullable()->constrained('dossier_records')->nullOnDelete();
            $table->timestamps();

            $table->index(['intake_id', 'kind', 'status']);
            $table->index(['company_id', 'key']);
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('dossier_evidence_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('intake_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dossier_subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dossier_record_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('evidence_type');
            $table->unsignedBigInteger('evidence_id');
            $table->string('relationship')->default('supports');
            $table->timestamps();

            $table->unique(
                ['dossier_subject_id', 'dossier_record_id', 'evidence_type', 'evidence_id'],
                'dossier_evidence_unique',
            );
            $table->index(['intake_id', 'evidence_type']);
        });

        Schema::create('dossier_decision_areas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('intake_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('label');
            $table->string('status');
            $table->string('next_action')->nullable();
            $table->text('blocker')->nullable();
            $table->foreignId('blocking_subject_id')->nullable()->constrained('dossier_subjects')->nullOnDelete();
            $table->json('cost_risks')->nullable();
            $table->json('evidence_summary')->nullable();
            $table->timestamp('assessed_at')->nullable();
            $table->timestamps();

            $table->unique(['intake_id', 'key']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('contribution_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('intake_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dossier_subject_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('intake_follow_up_item_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('audience');
            $table->string('type');
            $table->text('prompt');
            $table->string('decision_area_key')->nullable();
            $table->string('status');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('completed_by_type')->nullable();
            $table->unsignedBigInteger('completed_by_id')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['intake_id', 'audience', 'status']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('airco_rooms', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('intake_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dossier_subject_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('name');
            $table->string('use_type')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('status')->default('desired');
            $table->string('source_type');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->json('dimensions')->nullable();
            $table->timestamps();

            $table->unique(['intake_id', 'key']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('airco_placement_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('intake_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('airco_room_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('dossier_subject_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('label');
            $table->text('description')->nullable();
            $table->json('location_data')->nullable();
            $table->string('status')->default('candidate');
            $table->string('source_type');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->decimal('confidence', 4, 3)->nullable();
            $table->json('cost_risks')->nullable();
            $table->timestamps();

            $table->index(['intake_id', 'type', 'status']);
            $table->index(['company_id', 'type']);
        });

        Schema::create('airco_installation_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('intake_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->string('configuration_type');
            $table->unsignedSmallInteger('rank')->nullable();
            $table->string('status')->default('candidate');
            $table->text('summary')->nullable();
            $table->string('cost_impact')->nullable();
            $table->string('source_type')->default('installer');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->decimal('confidence', 4, 3)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('selected_at')->nullable();
            $table->timestamps();

            $table->index(['intake_id', 'status', 'rank']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('airco_installation_option_placements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('airco_installation_option_id')->constrained()->cascadeOnDelete();
            $table->foreignId('airco_placement_option_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(
                ['airco_installation_option_id', 'airco_placement_option_id'],
                'airco_option_placement_unique',
            );
        });

        Schema::create('airco_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('intake_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('airco_installation_option_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_placement_id')->nullable()->constrained('airco_placement_options')->nullOnDelete();
            $table->foreignId('to_placement_id')->nullable()->constrained('airco_placement_options')->nullOnDelete();
            $table->foreignId('dossier_subject_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('label');
            $table->string('status')->default('unknown');
            $table->string('length_class')->nullable();
            $table->json('segments')->nullable();
            $table->json('obstacles')->nullable();
            $table->json('uncertainties')->nullable();
            $table->string('cost_impact')->nullable();
            $table->decimal('confidence', 4, 3)->nullable();
            $table->string('source_type')->default('installer');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->boolean('safety_check_required')->default(false);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['intake_id', 'type', 'status']);
            $table->index(['company_id', 'type']);
        });

        Schema::table('pipe_route_sessions', function (Blueprint $table): void {
            $table->foreignId('airco_connection_id')
                ->nullable()
                ->after('intake_id')
                ->constrained('airco_connections')
                ->cascadeOnDelete();
            $table->unique('airco_connection_id');
        });

        Schema::create('installation_outcomes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('intake_id')->constrained()->cascadeOnDelete()->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('selected_installation_option_id')
                ->nullable()
                ->constrained('airco_installation_options')
                ->nullOnDelete();
            $table->string('result');
            $table->unsignedInteger('active_installer_minutes')->nullable();
            $table->unsignedInteger('customer_minutes')->nullable();
            $table->boolean('site_visit_occurred')->default(false);
            $table->json('site_visit_reasons')->nullable();
            $table->string('quote_type')->nullable();
            $table->string('installation_surprise')->nullable();
            $table->text('surprise_notes')->nullable();
            $table->json('proposal_delta')->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'result']);
            $table->index(['company_id', 'site_visit_occurred']);
        });

        $this->backfillDossierRoots();
    }

    public function down(): void
    {
        Schema::dropIfExists('installation_outcomes');

        Schema::table('pipe_route_sessions', function (Blueprint $table): void {
            $table->dropUnique(['airco_connection_id']);
            $table->dropConstrainedForeignId('airco_connection_id');
        });

        Schema::dropIfExists('airco_connections');
        Schema::dropIfExists('airco_installation_option_placements');
        Schema::dropIfExists('airco_installation_options');
        Schema::dropIfExists('airco_placement_options');
        Schema::dropIfExists('airco_rooms');
        Schema::dropIfExists('contribution_tasks');
        Schema::dropIfExists('dossier_decision_areas');
        Schema::dropIfExists('dossier_evidence_links');
        Schema::dropIfExists('dossier_records');
        Schema::dropIfExists('dossier_subjects');

        Schema::table('intake_follow_up_rounds', function (Blueprint $table): void {
            $table->dropColumn(['purpose', 'return_status']);
        });

        Schema::table('intake_uploads', function (Blueprint $table): void {
            $table->dropColumn([
                'analysis_path',
                'analysis_mime_type',
                'analysis_size_bytes',
                'analysis_checksum',
            ]);
        });

        Schema::table('intakes', function (Blueprint $table): void {
            $table->dropColumn(['workflow_mode', 'customer_access_enabled']);
        });
    }

    private function backfillDossierRoots(): void
    {
        DB::table('intakes')
            ->select(['id', 'company_id', 'created_at', 'updated_at'])
            ->orderBy('id')
            ->chunkById(100, function ($intakes): void {
                foreach ($intakes as $intake) {
                    if ($intake->company_id === null) {
                        continue;
                    }

                    DB::table('dossier_subjects')->insertOrIgnore([
                        'intake_id' => $intake->id,
                        'company_id' => $intake->company_id,
                        'parent_id' => null,
                        'type' => 'survey',
                        'key' => 'survey',
                        'label' => 'Technische opname',
                        'meta' => null,
                        'created_at' => $intake->created_at,
                        'updated_at' => $intake->updated_at,
                    ]);

                    $rootId = DB::table('dossier_subjects')
                        ->where('intake_id', $intake->id)
                        ->where('key', 'survey')
                        ->value('id');

                    if ($rootId !== null) {
                        $this->backfillEvidenceForIntake(
                            (int) $intake->id,
                            (int) $intake->company_id,
                            (int) $rootId,
                        );
                    }
                }
            });
    }

    private function backfillEvidenceForIntake(int $intakeId, int $companyId, int $rootId): void
    {
        $sources = [
            'intake_answers' => 'intake_answer',
            'intake_external_facts' => 'intake_external_fact',
            'intake_uploads' => 'intake_upload',
        ];

        foreach ($sources as $table => $type) {
            DB::table($table)
                ->where('intake_id', $intakeId)
                ->orderBy('id')
                ->chunkById(250, function ($rows) use ($intakeId, $companyId, $rootId, $type): void {
                    foreach ($rows as $row) {
                        DB::table('dossier_evidence_links')->insert([
                            'intake_id' => $intakeId,
                            'company_id' => $companyId,
                            'dossier_subject_id' => $rootId,
                            'dossier_record_id' => null,
                            'evidence_type' => $type,
                            'evidence_id' => $row->id,
                            'relationship' => 'supports',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                });
        }
    }
};
