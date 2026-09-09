<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('github_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->string('provider', 32)->default('github');
            $table->string('github_user_id', 64);
            $table->string('username', 191);
            $table->text('avatar_url')->nullable();
            $table->longText('access_token_encrypted');
            $table->longText('refresh_token_encrypted')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'provider', 'github_user_id']);
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });

        Schema::create('server_git_repositories', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('server_id');
            $table->unsignedBigInteger('github_account_id');
            $table->string('repository_id', 191);
            $table->string('repository_full_name', 191);
            $table->string('remote_url', 500);
            $table->string('default_branch', 191)->default('main');
            $table->string('current_branch', 191)->nullable();
            $table->string('working_directory', 500)->default('/home/container');
            $table->timestamps();

            $table->unique('server_id');
            $table->foreign('server_id')->references('id')->on('servers')->onDelete('cascade');
            $table->foreign('github_account_id')->references('id')->on('github_accounts')->onDelete('cascade');
        });

        Schema::create('git_operations', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('server_id');
            $table->unsignedInteger('user_id');
            $table->string('repository', 191)->nullable();
            $table->string('operation', 191);
            $table->string('branch', 191)->nullable();
            $table->string('commit_sha', 191)->nullable();
            $table->string('status', 32)->default('running');
            $table->longText('output')->nullable();
            $table->longText('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['server_id', 'started_at']);
            $table->foreign('server_id')->references('id')->on('servers')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('git_operations');
        Schema::dropIfExists('server_git_repositories');
        Schema::dropIfExists('github_accounts');
    }
};