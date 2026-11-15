<?php

namespace App\Http\Controllers;

use App\Models\Menu;
use App\Models\MenuIngredient;
use App\Models\StockItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class MenuController extends Controller
{
    private const STATUS = ['tersedia', 'kosong'];

    public function index()
    {
        // Kategori sekarang berupa enum, bukan tabel.
        // Daftar menu dimuat lewat endpoint menu.data (JavaScript).
        $categories = collect(Menu::KATEGORI)->map(fn ($k) => (object) [
            'id_categorie' => $k,
            'name'         => ucfirst($k),
            'is_active'    => 1,
        ]);

        return view('menu.index', [
            'pageKey'    => 'menu',
            'categories' => $categories,
        ]);
    }

    public function store(Request $request)
    {
        if (! $request->has('kode_menu') && $request->has('code')) {
            $request->merge(['kode_menu' => $request->input('code')]);
        }

        $validated = $request->validate([
            // Kode boleh kosong, nanti dibuat otomatis (MN001, MN002, ...)
            'kode_menu'               => 'nullable|string|max:10|unique:ms_menu,kode_menu',
            'name'                    => 'required|string|max:45',
            'description'             => 'nullable|string|max:200',
            'price'                   => 'required|integer|min:1',
            'biaya_kemasan'           => 'nullable|integer|min:0',
            'categories_id_categorie' => ['required', Rule::in(Menu::KATEGORI)],
            'image'                   => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
        ], [
            'kode_menu.unique' => 'Kode menu sudah digunakan.',
            'kode_menu.max'    => 'Kode menu maksimal 10 karakter.',
            'name.required'    => 'Nama menu wajib diisi.',
            'price.required'   => 'Harga wajib diisi.',
        ]);

        $imagePath = $request->hasFile('image')
            ? $request->file('image')->store('menus', 'public')
            : '';

        $menu = Menu::create([
            'kode_menu'     => $validated['kode_menu'] ?? $this->nextKode(),
            'nama_menu'     => $validated['name'],
            'kategori'      => $validated['categories_id_categorie'],
            'harga'         => $validated['price'],
            'deskripsi'     => $validated['description'] ?? '',
            'image'         => $imagePath,
            'status'        => 'tersedia',
            'biaya_kemasan' => $validated['biaya_kemasan'] ?? 0,
        ]);

        return response()->json([
            'message'   => 'Menu berhasil ditambahkan.',
            'menu'      => $menu,
            'code'      => $menu->code,
            'kode_menu' => $menu->kode_menu,
        ], 201);
    }

    public function update(Request $request, Menu $menu)
    {
        if (! $request->has('kode_menu') && $request->has('code')) {
            $request->merge(['kode_menu' => $request->input('code')]);
        }

        $validated = $request->validate([
            'kode_menu'               => 'sometimes|required|string|max:10|unique:ms_menu,kode_menu,' . $menu->id_menu . ',id_menu',
            'name'                    => 'required|string|max:45',
            'description'             => 'nullable|string|max:200',
            'price'                   => 'required|integer|min:1',
            'biaya_kemasan'           => 'nullable|integer|min:0',
            'status'                  => ['sometimes', Rule::in(self::STATUS)],
            'categories_id_categorie' => ['required', Rule::in(Menu::KATEGORI)],
            'image'                   => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
        ], [
            'kode_menu.required' => 'Kode menu wajib diisi.',
            'kode_menu.unique'   => 'Kode menu sudah digunakan.',
            'kode_menu.max'      => 'Kode menu maksimal 10 karakter.',
            'name.required'      => 'Nama menu wajib diisi.',
            'price.required'     => 'Harga wajib diisi.',
        ]);

        $payload = [
            'nama_menu' => $validated['name'],
            'kategori'  => $validated['categories_id_categorie'],
            'harga'     => $validated['price'],
            'deskripsi' => $validated['description'] ?? '',
        ];

        if (isset($validated['kode_menu'])) {
            $payload['kode_menu'] = $validated['kode_menu'];
        }

        if (isset($validated['biaya_kemasan'])) {
            $payload['biaya_kemasan'] = $validated['biaya_kemasan'];
        }

        // Menu yang sempat dinonaktifkan bisa diaktifkan lagi
        if (isset($validated['status'])) {
            $payload['status'] = $validated['status'];
        }

        if ($request->hasFile('image')) {
            if ($menu->image) {
                Storage::disk('public')->delete($menu->image);
            }
            $payload['image'] = $request->file('image')->store('menus', 'public');
        }

        $menu->update($payload);

        $menu = $menu->fresh();

        return response()->json([
            'message'   => 'Menu berhasil diperbarui.',
            'menu'      => $menu,
            'code'      => $menu->code,
            'kode_menu' => $menu->kode_menu,
        ]);
    }

    public function destroy(Menu $menu)
    {
        return DB::transaction(function () use ($menu) {
            // Menu yang sudah punya riwayat (penjualan atau stok) tidak boleh dihapus
            $hasHistory = DB::table('tr_penjualan')->where('id_menu', $menu->id_menu)->exists()
                || DB::table('ms_stok')->where('id_menu', $menu->id_menu)->exists();

            if ($hasHistory) {
                $menu->update(['status' => 'kosong']);

                return response()->json([
                    'action'  => 'deactivated',
                    'message' => "Menu '{$menu->name}' telah memiliki riwayat transaksi, sehingga statusnya otomatis dinonaktifkan agar tidak merusak data historis.",
                ]);
            }

            MenuIngredient::where('id_menu', $menu->id_menu)->delete();

            $imagePath = $menu->image;

            $menu->delete();

            if ($imagePath && Storage::disk('public')->exists($imagePath)) {
                Storage::disk('public')->delete($imagePath);
            }

            return response()->json([
                'action'  => 'deleted',
                'message' => 'Menu beserta resepnya berhasil dihapus.',
            ]);
        });
    }

    public function data()
    {
        $menus = Menu::orderBy('id_menu')
            ->get()
            ->map(function ($menu) {
                $menu->image_url = $this->imageUrl($menu->image);

                return $menu;
            });

        return response()->json($menus);
    }

    /**
     * Estimasi jumlah porsi yang masih bisa dibuat dari stok bahan.
     * null = menu belum punya resep.
     */
    private function estimatePortions(Menu $menu): ?int
    {
        if ($menu->ingredients->isEmpty()) {
            return null;
        }

        $min = PHP_INT_MAX;

        foreach ($menu->ingredients as $ingredient) {
            $stockItem = $ingredient->stockItem;

            if (! $stockItem || ! $stockItem->is_active) {
                return 0;
            }

            $need = $ingredient->quantity_required;
            if ($need <= 0) {
                continue; // hindari pembagian nol
            }

            $min = min($min, (int) floor(max(0, $stockItem->current_stock) / $need));
        }

        return $min === PHP_INT_MAX ? null : $min;
    }

    // Data menu untuk halaman Kasir (hanya menu aktif) beserta estimasi porsi
    public function kasirData()
    {
        $menus = Menu::with(['ingredients.stockItem'])
            ->where('status', 'tersedia')
            ->orderBy('nama_menu')
            ->get()
            ->map(fn ($menu) => [
                'id'             => $menu->id_menu,
                'code'           => $menu->code,
                'kode_menu'      => $menu->kode_menu,
                'name'           => $menu->name,
                'price'          => $menu->price,
                'packaging_cost' => (int) $menu->biaya_kemasan,
                'category_id'    => $menu->kategori,
                'category'       => ucfirst($menu->kategori),
                'has_recipe'     => $menu->ingredients->isNotEmpty(),
                'stock_estimate' => $this->estimatePortions($menu),
                'image_url'      => $this->imageUrl($menu->image),
            ]);

        return response()->json($menus);
    }

    /**
     * Endpoint ringan untuk memperbarui estimasi porsi seluruh menu aktif.
     */
    public function stockEstimates()
    {
        $menus = Menu::with(['ingredients.stockItem'])
            ->where('status', 'tersedia')
            ->get()
            ->map(fn ($menu) => [
                'id'             => $menu->id_menu,
                'has_recipe'     => $menu->ingredients->isNotEmpty(),
                'stock_estimate' => $this->estimatePortions($menu),
            ]);

        return response()->json($menus);
    }

    private function mapIngredients(Menu $menu)
    {
        return $menu->ingredients()
            ->with('stockItem')
            ->get()
            ->map(fn ($ing) => [
                'id_menu_ingredient' => $ing->id_resep,
                'id_stock_item'      => $ing->id_bahan,
                'name'               => $ing->stockItem?->name ?? 'Bahan tidak ditemukan',
                'unit'               => $ing->stockItem?->unit ?? '-',
                'quantity_required'  => $ing->quantity_required,
                'current_stock'      => $ing->stockItem?->current_stock ?? 0,
            ]);
    }

    /**
     * Data resep satu menu beserta daftar bahan baku yang tersedia.
     */
    public function getRecipe(Request $request, Menu $menu)
    {
        $this->ensureOwner($request, 'Hanya Owner yang dapat mengakses pengaturan resep.');

        $availableStockItems = StockItem::where('status', 'aktif')
            ->orderBy('nama_bahan')
            ->get()
            ->map(fn ($item) => [
                'id_stock_item' => $item->id_bahan,
                'name'          => $item->name,
                'unit'          => $item->unit,
                'current_stock' => $item->current_stock,
            ]);

        return response()->json([
            'menu' => [
                'id_menu'   => $menu->id_menu,
                'name'      => $menu->name,
                'code'      => $menu->code,
                'kode_menu' => $menu->kode_menu,
                'price'     => $menu->price,
            ],
            'ingredients'           => $this->mapIngredients($menu),
            'available_stock_items' => $availableStockItems,
        ]);
    }

    /**
     * Simpan resep menu. Tidak mengubah stok bahan baku.
     */
    public function saveRecipe(Request $request, Menu $menu)
    {
        $this->ensureOwner($request, 'Hanya Owner yang dapat mengatur resep menu.');

        $validated = $request->validate([
            'ingredients'                     => 'nullable|array',
            'ingredients.*.id_stock_item'     => 'required|integer|exists:ms_bahan,id_bahan',
            'ingredients.*.quantity_required' => 'required|numeric|gt:0',
        ], [
            'ingredients.*.id_stock_item.required'     => 'Bahan baku wajib dipilih.',
            'ingredients.*.id_stock_item.exists'       => 'Bahan baku tidak valid.',
            'ingredients.*.quantity_required.required' => 'Jumlah bahan baku wajib diisi.',
            'ingredients.*.quantity_required.numeric'  => 'Jumlah bahan baku harus berupa angka.',
            'ingredients.*.quantity_required.gt'       => 'Jumlah bahan baku harus lebih besar dari 0.',
        ]);

        $ingredientsData = $validated['ingredients'] ?? [];

        $stockIds = array_column($ingredientsData, 'id_stock_item');
        if (count($stockIds) !== count(array_unique($stockIds))) {
            return response()->json([
                'message' => 'Bahan baku yang sama tidak boleh dimasukkan lebih dari satu kali dalam resep ini.',
            ], 422);
        }

        // Bahan nonaktif tidak boleh dipilih untuk resep baru
        $existingStockIds = MenuIngredient::where('id_menu', $menu->id_menu)
            ->pluck('id_bahan')
            ->map(fn ($v) => (int) $v)
            ->toArray();

        foreach ($ingredientsData as $item) {
            if (! in_array((int) $item['id_stock_item'], $existingStockIds, true)) {
                $stockItem = StockItem::find($item['id_stock_item']);
                if (! $stockItem || ! $stockItem->is_active) {
                    $name = $stockItem ? $stockItem->name : 'Bahan baku';

                    return response()->json([
                        'message' => "Bahan baku '{$name}' sudah nonaktif dan tidak dapat dipilih untuk resep baru.",
                    ], 422);
                }
            }
        }

        DB::transaction(function () use ($menu, $ingredientsData) {
            MenuIngredient::where('id_menu', $menu->id_menu)->delete();

            foreach ($ingredientsData as $item) {
                [$bahan, $produk] = MenuIngredient::splitPerPortion((float) $item['quantity_required']);

                MenuIngredient::create([
                    'id_menu'       => $menu->id_menu,
                    'id_bahan'      => $item['id_stock_item'],
                    'jumlah_bahan'  => $bahan,
                    'jumlah_produk' => $produk,
                ]);
            }
        });

        return response()->json([
            'message'     => 'Resep menu berhasil disimpan.',
            'ingredients' => $this->mapIngredients($menu),
        ]);
    }

    /**
     * Hapus satu bahan dari resep menu.
     */
    public function destroyRecipeIngredient(Request $request, Menu $menu, MenuIngredient $ingredient)
    {
        $this->ensureOwner($request, 'Hanya Owner yang dapat mengatur resep menu.');

        if ((int) $ingredient->id_menu !== (int) $menu->id_menu) {
            return response()->json([
                'message' => 'Bahan tidak terkait dengan menu ini.',
            ], 422);
        }

        $ingredient->delete();

        return response()->json([
            'message' => 'Bahan berhasil dihapus dari resep.',
        ]);
    }

    /* ------------------------------------------------------------ HELPER */

    // Pengaturan resep hanya untuk Owner (superadmin)
    private function ensureOwner(Request $request, string $message): void
    {
        if ($request->user()->role !== 'superadmin') {
            abort(403, $message);
        }
    }

    // asset() memakai alamat yang sedang dibuka, jadi gambar tetap tampil di 127.0.0.1 maupun lewat IP laptop (HP)
    private function imageUrl(?string $path): ?string
    {
        return ($path && Storage::disk('public')->exists($path))
            ? asset('storage/' . $path)
            : null;
    }

    // Kode otomatis berikutnya, mis. MN014
    private function nextKode(): string
    {
        $max = (int) DB::table('ms_menu')
            ->where('kode_menu', 'like', 'MN%')
            ->selectRaw('MAX(CAST(SUBSTRING(kode_menu, 3) AS UNSIGNED)) as n')
            ->value('n');

        return 'MN' . str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
    }
}