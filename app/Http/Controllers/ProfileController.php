<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class ProfileController extends Controller
{
    public function update(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            // batas max disesuaikan dengan kolom ms_user (nama 45, username 45;
            // username dibatasi 30 supaya sama dengan form Pengguna)
            'name'             => 'required|string|max:45',
            'username'         => 'required|string|max:30|unique:ms_user,username,' . $user->id_user . ',id_user',
            'password'         => 'nullable|string|min:6|confirmed',
            'current_password' => 'nullable|string',
        ], [
            'password.confirmed' => 'Konfirmasi password tidak sama.',
            'password.min'       => 'Password baru minimal 6 karakter.',
            'username.unique'    => 'Username sudah dipakai.',
        ]);

        // Ganti password: password lama wajib benar
        if (!empty($data['password'])) {
            if (!Hash::check($data['current_password'] ?? '', $user->password)) {
                return response()->json([
                    'errors' => ['current_password' => ['Password lama salah.']],
                ], 422);
            }
            $user->password = Hash::make($data['password']);
        }

        // Input form tetap bernama "name", tapi kolom di database: nama
        $user->nama = $data['name'];
        $user->username = $data['username'];
        $user->save();

        return response()->json([
            'name'     => $user->nama,
            'username' => $user->username,
        ]);
    }
}