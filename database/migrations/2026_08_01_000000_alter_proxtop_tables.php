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
        if (!Schema::hasColumn('proxtop_server_vms', 'media_item_id')) {
            Schema::table('proxtop_server_vms', function (Blueprint $table) {
                $table->unsignedBigInteger('media_item_id')->nullable()->after('name_intern')->comment('Image');
                $table->foreign('media_item_id')->references('id')->on('media_items')->cascadeOnUpdate();
                $table->dropColumn('icon');
            });
        }

        if (!Schema::hasColumn('proxtop_observe_server_items', 'media_item_id')) {
            Schema::table('proxtop_observe_server_items', function (Blueprint $table) {
                $table->unsignedBigInteger('media_item_id')->nullable()->after('name_intern')->comment('Image');
                $table->foreign('media_item_id')->references('id')->on('media_items')->cascadeOnUpdate();
                $table->dropColumn('icon');
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
        Schema::table('proxtop_observe_server_items', function (Blueprint $table) {
            $table->dropColumn('media_item_id');
        });
        Schema::table('proxtop_server_vms', function (Blueprint $table) {
            $table->dropColumn('media_item_id');
        });
    }

};
