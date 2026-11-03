<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The questionnaire of a campaign, asked by the agent during the call.
        Schema::create('survey_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            // single, multiple, yes_no, rating, nps, number, text, long_text, date
            $table->string('type', 20);
            $table->string('label', 300);
            $table->string('help', 500)->nullable();
            $table->boolean('required')->default(false);
            // Choices of single / multiple: [{"value": "a1b2", "label": "Très satisfait"}]
            $table->json('options')->nullable();
            // rating: {"min": 1, "max": 5, "min_label": "…", "max_label": "…"}; number: {"min", "max"}
            $table->json('settings')->nullable();
            // Shown only when another question got one of these answers:
            // {"question_id": 12, "values": ["a1b2", "yes"]}
            $table->json('condition')->nullable();
            $table->timestamps();

            $table->index(['campaign_id', 'position']);
        });

        // One response per contact; later calls to the same contact continue it.
        Schema::create('survey_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_attempt_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // {"<question id>": value}
            $table->json('answers');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'campaign_contact_id']);
            $table->index(['campaign_id', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_responses');
        Schema::dropIfExists('survey_questions');
    }
};
