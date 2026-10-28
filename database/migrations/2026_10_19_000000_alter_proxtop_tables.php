<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        if (!Schema::hasColumn('proxtop_property_templates', 'acl_res_view')) {
            Schema::table('proxtop_property_templates', function (Blueprint $table) {
                $table->string('acl_res_view')
                    ->nullable()
                    ->after('tags')
                    ->comment('acl resources as json to allow to show');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('proxtop_property_templates', function (Blueprint $table) {
            $table->dropColumn('acl_res_view');
        });
    }
};
