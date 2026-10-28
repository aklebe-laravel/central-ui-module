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

        if (!Schema::hasTable('proxtop_property_templates')) {
            Schema::create('proxtop_property_templates', function (Blueprint $table) {
                $table->id();
                $table->string('name')
                    ->nullable();
                $table->boolean('is_enabled')
                    ->default(true);
                $table->string('tags', 2000)
                    ->nullable();
                $table->string('acl_res_shutdown')
                    ->nullable()
                    ->comment('acl resources as json to allow a shutdown');
                $table->string('acl_res_start')
                    ->nullable()
                    ->comment('acl resources as json to allow a start');
                $table->string('acl_res_edit')
                    ->nullable()
                    ->comment('acl resources as json to allow editing');
                $table->double('net_in_out_ratio')
                    ->nullable()
                    ->comment('like 3.0 for 3:1 for in:out');
                $table->unsignedInteger('net_traffic_idle')
                    ->default(1024)
                    ->comment('The threshold for filtering traffic as noise');
                $table->unsignedInteger('net_traffic_limit')
                    ->default(20 * 1024 * 1024)
                    ->comment('Not the cap, just a realistic max usage');
                $table->unsignedInteger('disk_traffic_idle')
                    ->default(1024)
                    ->comment('The threshold for filtering traffic as noise');
                $table->unsignedInteger('disk_traffic_limit')
                    ->default(600 * 1024 * 1024)
                    ->comment('Not the cap, just a realistic max usage');
                //$table->unsignedInteger('net_traffic_calc_tolerance')->default(95)->comment('Not the cap, just a realistic max usage');
                $table->unsignedInteger('cpu_idle')
                    ->default(5)
                    ->comment('The threshold for filtering traffic as noise');
                $table->unsignedInteger('cpu_limit')
                    ->default(95)
                    ->comment('Not the cap, just a realistic max usage');
                $table->unsignedInteger('cpu_calc_tolerance')
                    ->default(95)
                    ->comment('Not the cap, just a realistic max usage');
                $table->json('options')
                    ->nullable();
                $table->string('description')
                    ->nullable();
                $table->timestamps(6);
            });
        }

        if (!Schema::hasTable('proxtop_property_template_schedules')) {
            Schema::create('proxtop_property_template_schedules', function (Blueprint $table) {
                $table->id();
                $table->boolean('is_enabled')
                    ->default(true);
                $table->foreignId('template_id')
                    ->constrained('proxtop_property_templates')
                    ->cascadeOnDelete();

                $table->string('command_code');
                $table->string('cron_expression')
                    ->nullable()
                    ->comment('null = always');
                $table->unsignedInteger('duration')
                    ->default(0)
                    ->comment('duration in seconds');

                $table->date('starts_at_date')
                    ->nullable();
                $table->date('ends_at_date')
                    ->nullable();
                $table->timestamps(6);
            });
        }

        if (!Schema::hasTable('proxtop_vm_property_template')) {
            Schema::create('proxtop_vm_property_template', function (Blueprint $table) {
                $table->foreignId('vm_id')
                    ->constrained('proxtop_server_vms')
                    ->cascadeOnDelete();
                $table->foreignId('template_id')
                    ->constrained('proxtop_property_templates')
                    ->cascadeOnDelete();
                $table->primary(['vm_id', 'template_id']);
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
        Schema::dropIfExists('proxtop_vm_property_template');
        Schema::dropIfExists('proxtop_property_template_schedules');
        Schema::dropIfExists('proxtop_property_templates');
    }

};
