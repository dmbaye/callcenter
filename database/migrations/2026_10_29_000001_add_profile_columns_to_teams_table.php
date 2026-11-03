<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Company profile of the organization, for segmentation, marketing and sales follow-up.
        // Choice columns hold the keys of config/organization.php.
        Schema::table('teams', function (Blueprint $table) {
            $table->string('legal_name')->nullable()->after('name');
            $table->string('industry', 40)->nullable()->after('legal_name');
            $table->string('company_size', 20)->nullable()->after('industry');
            $table->unsignedSmallInteger('planned_agents')->nullable()->after('company_size');
            $table->string('use_case', 40)->nullable()->after('planned_agents');
            $table->string('country', 10)->nullable()->after('use_case');
            $table->string('city', 100)->nullable()->after('country');
            $table->string('address')->nullable()->after('city');
            $table->string('phone', 30)->nullable()->after('address');
            $table->string('contact_email')->nullable()->after('phone');
            $table->string('website')->nullable()->after('contact_email');
            // Malian business registry and tax numbers (Registre du commerce, Numéro d'identification fiscale).
            $table->string('rccm', 50)->nullable()->after('website');
            $table->string('nif', 50)->nullable()->after('rccm');
            $table->string('acquisition_source', 40)->nullable()->after('nif');
            $table->boolean('marketing_opt_in')->default(false)->after('acquisition_source');
            $table->timestamp('profile_completed_at')->nullable()->after('marketing_opt_in');

            $table->index('industry');
            $table->index('company_size');
            $table->index('country');
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropIndex(['industry']);
            $table->dropIndex(['company_size']);
            $table->dropIndex(['country']);
            $table->dropColumn([
                'legal_name', 'industry', 'company_size', 'planned_agents', 'use_case',
                'country', 'city', 'address', 'phone', 'contact_email', 'website',
                'rccm', 'nif', 'acquisition_source', 'marketing_opt_in', 'profile_completed_at',
            ]);
        });
    }
};
