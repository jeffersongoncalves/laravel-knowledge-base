<?php

namespace JeffersonGoncalves\KnowledgeBase\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class User extends Model
{
    protected $fillable = ['name', 'email'];

    public static function createTable(): void
    {
        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('email');
                $table->timestamps();
            });
        }
    }
}
