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
        if (!Schema::hasTable('sayings')) {
            Schema::create('sayings', function (Blueprint $table) {
                $table->id();
                $table->string('author', 255)->nullable()->comment('author');
                $table->text('content')->nullable();
                $table->string('tags', 255)->nullable();
                $table->timestamps(6); // 6 = microsecond precision
            });
        }

        if (Schema::hasTable('sayings')) {
            $sql = file_get_contents(__DIR__.'/../raw_sql/sayings.sql');
            DB::unprepared($sql);
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('sayings');
    }

};
