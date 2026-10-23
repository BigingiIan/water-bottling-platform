<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('error_events', function (Blueprint $table): void {
            $table->renameColumn('url', 'path');
        });

        Schema::table('error_events', function (Blueprint $table): void {
            $table->longText('exception_message')->nullable();
            $table->string('exception_file', 2048)->nullable();
            $table->unsignedInteger('exception_line')->nullable();
            $table->longText('trace')->nullable();
            $table->char('fingerprint', 64)->nullable();
            $table->string('role_slug')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->index(['fingerprint', 'created_at']);
        });

        DB::table('error_events')
            ->select(['id', 'path'])
            ->orderBy('id')
            ->chunkById(500, function ($events): void {
                foreach ($events as $event) {
                    $path = parse_url($event->path, PHP_URL_PATH);

                    DB::table('error_events')
                        ->where('id', $event->id)
                        ->update([
                            'path' => is_string($path) && $path !== '' ? mb_substr($path, 0, 2048) : '/',
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('error_events', function (Blueprint $table): void {
            $table->dropIndex(['fingerprint', 'created_at']);
            $table->dropColumn([
                'exception_message',
                'exception_file',
                'exception_line',
                'trace',
                'fingerprint',
                'role_slug',
                'ip_address',
                'resolved_at',
                'resolution_note',
            ]);
            $table->renameColumn('path', 'url');
        });
    }
};
