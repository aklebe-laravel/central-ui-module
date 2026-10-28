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
        if (!Schema::hasTable('proxtop_servers')) {
            Schema::create('proxtop_servers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('datacenter_id')->nullable()->index();
                $table->boolean('api_allowed')->default(true)->comment('True when api can be used');
                $table->string('host', 255)->nullable()->comment('the server host ip or url');
                $table->string('user', 255)->nullable()->comment('user id user id like root@pam');
                $table->string('token_name', 255)->nullable()->comment('token id like my-api-token');
                $table->string('token_secret', 255)->nullable()->comment('token value like af5cda88-6d2b-416c-...');
                $table->string('description', 255)->nullable();
                $table->timestamps(6); // 6 = microsecond precision
            });
        }

        if (!Schema::hasTable('proxtop_server_vms')) {
            Schema::create('proxtop_server_vms', function (Blueprint $table) {
                $table->id();
                $table->string('vm_type', 255)->nullable()->comment('like vm, lxc or custom');
                $table->unsignedBigInteger('server_id')->nullable()->index();
                $table->foreign('server_id')->references('id')->on('proxtop_servers')->cascadeOnUpdate()->cascadeOnDelete();
                $table->string('extern_vm_id', 255)->nullable();
                $table->string('node', 255)->nullable();
                $table->string('name', 255)->nullable();
                $table->string('name_intern', 255)->nullable()->comment('frontend label');
                $table->string('icon', 255)->nullable();
                $table->string('description', 255)->nullable();
                $table->timestamps(6); // 6 = microsecond precision
            });
        }

        if (!Schema::hasTable('proxtop_observe_server_items')) {
            Schema::create('proxtop_observe_server_items', function (Blueprint $table) {
                $table->id();
                $table->boolean('is_enabled')->default(true)->comment('False will not respected by api');
                $table->double('position')->default(1000)->comment('user to sort items');
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnUpdate()->cascadeOnDelete();
                $table->unsignedBigInteger('vm_id')->nullable()->index();
                $table->foreign('vm_id')->references('id')->on('proxtop_server_vms')->cascadeOnUpdate()->cascadeOnDelete();
                $table->string('name_intern', 255)->nullable()->comment('frontend label');
                $table->string('icon', 255)->nullable();
                $table->string('description', 255)->nullable();
                $table->timestamps(6); // 6 = microsecond precision
            });
        }

        if (!Schema::hasTable('proxtop_server_actions')) {
            Schema::create('proxtop_server_actions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('vm_id')->nullable()->index();
                $table->foreign('vm_id')->references('id')->on('proxtop_server_vms')->cascadeOnUpdate()->cascadeOnDelete();
                $table->unsignedBigInteger('user_id')->index();
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnUpdate()->cascadeOnDelete();
                $table->string('action', 255)->nullable()->comment('action like start or shutdown');
                $table->timestamp('scheduled_at', 6)->nullable();
                $table->timestamps(6); // 6 = microsecond precision
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
        Schema::dropIfExists('proxtop_server_actions');
        Schema::dropIfExists('proxtop_observe_server_items');
        Schema::dropIfExists('proxtop_server_vms');
        Schema::dropIfExists('proxtop_servers');
    }

};
