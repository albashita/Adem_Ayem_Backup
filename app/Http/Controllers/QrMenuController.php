<?php

namespace App\Http\Controllers;

use App\Models\Menu;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class QrMenuController extends Controller
{
    public function index(): View
    {
        return view('menu.qr-menu');
    }

    public function data(): JsonResponse
    {
        // Halaman publik: hanya menu yang tersedia, dikelompokkan per kategori
        $menus = Menu::where('status', 'tersedia')
            ->orderByRaw("FIELD(kategori, 'makanan', 'minuman', 'camilan')")
            ->orderBy('nama_menu')
            ->get()
            ->map(fn ($menu) => [
                'id_menu'     => $menu->id_menu,
                'name'        => $menu->name,
                'description' => $menu->description,
                'price'       => $menu->price,
                'image_url'   => $this->imageUrl($menu->image),
                'kategori'    => $menu->kategori,
                'category'    => $menu->kategori
                    ? ['id' => $menu->kategori, 'name' => ucfirst($menu->kategori)]
                    : null,
            ]);

        return response()->json($menus);
    }

    // asset() memakai alamat yang sedang dibuka, jadi gambar tetap tampil di HP
    private function imageUrl(?string $path): ?string
    {
        return ($path && Storage::disk('public')->exists($path))
            ? asset('storage/' . $path)
            : null;
    }
}