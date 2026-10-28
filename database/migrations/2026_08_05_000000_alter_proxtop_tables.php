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
        if (!Schema::hasIndex('proxtop_server_vms', 'proxtop_server_vms_unique1')) {
            Schema::table('proxtop_server_vms', function (Blueprint $table) {
                $table->unique(['server_id', 'extern_vm_id'], 'proxtop_server_vms_unique1');
            });
        }
        if (!Schema::hasIndex('proxtop_property_template_schedules', 'proxtop_property_template_schedules_unique1')) {
            Schema::table('proxtop_property_template_schedules', function (Blueprint $table) {
                $table->unique(['template_id', 'command_code'], 'proxtop_property_template_schedules_unique1');
            });
        }
        if (!Schema::hasIndex('proxtop_observe_server_items', 'proxtop_observe_server_items_unique1')) {
            Schema::table('proxtop_observe_server_items', function (Blueprint $table) {
                $table->unique(['user_id', 'vm_id'], 'proxtop_observe_server_items_unique1');
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
            $table->dropUnique(['user_id', 'vm_id']);
        });
        Schema::table('proxtop_property_template_schedules', function (Blueprint $table) {
            $table->dropUnique(['template_id', 'command_code']);
        });
        Schema::table('proxtop_server_vms', function (Blueprint $table) {
            $table->dropUnique(['template_id', 'command_code']);
        });
    }

};
