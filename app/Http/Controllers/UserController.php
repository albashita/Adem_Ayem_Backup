<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    private const ROLES = ['superadmin', 'admin', 'kasir'];

    public function index()
    {
        return view('pengguna.index', [
            'pageKey' => 'pengguna',
            'users'   => User::orderBy('id_user')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:45'],
            'username' => ['required', 'string', 'max:30', 'unique:ms_user,username'],
            'password' => ['required', 'string', 'min:6', 'max:50'],
            'role'     => ['required', Rule::in(self::ROLES)],
        ]);

        User::create([
            'nama'     => $data['name'],
            'username' => $data['username'],
            'password' => $data['password'],
            'role'     => $data['role'],
        ]);

        return redirect()->route('pengguna.index')->with('success', 'Pengguna berhasil ditambahkan.');
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:45'],
            'username' => ['required', 'string', 'max:30',
                           Rule::unique('ms_user', 'username')->ignore($user->id_user, 'id_user')],
            'password' => ['nullable', 'string', 'min:6', 'max:50'],
            'role'     => ['required', Rule::in(self::ROLES)],
        ]);

        // DIUBAH: Owner tidak boleh menurunkan role akunnya sendiri
        if ($user->id_user === $request->user()->id_user && $data['role'] !== $user->role) {
            return back()
                ->withInput()
                ->withErrors(['role' => 'Anda tidak bisa mengubah role akun sendiri.']);
        }

        $payload = [
            'nama'     => $data['name'],
            'username' => $data['username'],
            'role'     => $data['role'],
        ];

        // kosong = password tidak diubah
        if (! empty($data['password'])) {
            $payload['password'] = $data['password'];
        }

        $user->update($payload);

        return redirect()->route('pengguna.index')->with('success', 'Pengguna berhasil diperbarui.');
    }

    public function destroy(Request $request, User $user)
    {
        if ($user->id_user === $request->user()->id_user) {
            return back()->withErrors(['hapus' => 'Tidak bisa menghapus akun sendiri.']);
        }

        try {
            $user->delete();
        } catch (QueryException $e) {
            // user sudah punya riwayat transaksi/stok, tidak boleh dihapus
            return back()->withErrors(['hapus' => 'Pengguna ini sudah punya riwayat data, jadi tidak bisa dihapus.']);
        }

        return redirect()->route('pengguna.index')->with('success', 'Pengguna berhasil dihapus.');
    }
}