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
        if (!Schema::hasColumn('proxtop_server_vms', 'host')) {
            Schema::table('proxtop_server_vms', function (Blueprint $table) {
                $table->string('host', 255)->nullable()->after('icon')->comment('the vm host ip (or name)');
                $table->string('url', 255)->nullable()->after('host')->comment('url to frontend or empty');
                $table->json('options')->nullable()->after('url')->comment('specific options');
                $table->boolean('is_enabled')->default(true)->after('id')->comment('disabled = no listing, no actions');
                $table->timestamp('last_seen_at')->nullable()->after('description')->comment('used for late deletion');
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
        Schema::table('proxtop_server_vms', function (Blueprint $table) {
            $table->dropColumn('last_seen_at');
            $table->dropColumn('is_enabled');
            $table->dropColumn('options');
            $table->dropColumn('url');
            $table->dropColumn('host');
        });
    }

};
