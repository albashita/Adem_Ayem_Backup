<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Foundation\Auth\User as Authenticatable;

#[Fillable(['nama', 'username', 'password', 'role', 'status'])]
#[Hidden(['password'])]
class User extends Authenticatable
{
    protected $table = 'ms_user';
    protected $primaryKey = 'id_user';

    // tabel ms_user tidak punya kolom remember_token
    protected $rememberTokenName = '';

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    // Supaya view lama yang memakai $user->name tetap jalan (kolom aslinya: nama)
    protected function name(): Attribute
    {
        return Attribute::get(fn () => $this->nama);
    }
}