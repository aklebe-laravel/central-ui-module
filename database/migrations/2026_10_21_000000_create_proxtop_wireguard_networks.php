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
        if (!Schema::hasTable('proxtop_wireguard_networks')) {
            Schema::create('proxtop_wireguard_networks', function (Blueprint $table) {
                $table->id();
                $table->double('position')
                    ->default(1000)
                    ->comment('used to sort the networks');
                $table->string('tunnel', 32)
                    ->unique()
                    ->comment('pfSense tunnel name like tun_wg1');
                $table->string('host_group', 50)
                    ->nullable()
                    ->index()
                    ->comment('networks with the same group share their host numbers');
                $table->string('prefix', 16)
                    ->comment('client ip prefix like 10.0.99.');
                $table->unsignedTinyInteger('first_host')
                    ->default(2)
                    ->comment('first host number to assign');
                $table->unsignedTinyInteger('mask')
                    ->default(24);
                $table->string('endpoint', 255)
                    ->comment('host:port the clients connect to');
                $table->string('allowed_ips', 1000)
                    ->comment('AllowedIPs of the client config');
                $table->string('dns', 255)
                    ->comment('DNS of the client config');
                $table->unsignedSmallInteger('mtu')
                    ->default(1280);
                $table->string('description', 255)
                    ->nullable();
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
        Schema::dropIfExists('proxtop_wireguard_networks');
    }
};
