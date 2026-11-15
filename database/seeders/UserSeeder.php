<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            ['nama' => 'Owner', 'username' => 'owner', 'role' => 'superadmin'],
            ['nama' => 'Admin', 'username' => 'admin', 'role' => 'admin'],
            ['nama' => 'Kasir', 'username' => 'kasir', 'role' => 'kasir'],
        ];

        foreach ($users as $u) {
            DB::table('ms_user')->updateOrInsert(
                ['username' => $u['username']],
                $u + [
                    'password'   => Hash::make('password123'),
                    'status'     => 'aktif',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}