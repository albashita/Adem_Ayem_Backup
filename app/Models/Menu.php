<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class Menu extends Model
{
    public const KATEGORI = ['makanan', 'minuman', 'camilan'];

    protected $table = 'ms_menu';
    protected $primaryKey = 'id_menu';

    protected $fillable = [
        'kode_menu', 'nama_menu', 'kategori', 'harga',
        'deskripsi', 'image', 'status', 'biaya_kemasan',
    ];

    // Nama atribut lama tetap tersedia agar JSON ke JavaScript tidak berubah
    protected $appends = [
        'code', 'name', 'description', 'price',
        'is_active', 'categories_id_categorie', 'category',
    ];

    // DIUBAH: biaya_kemasan ikut di-cast
    protected $casts = [
        'harga'         => 'integer',
        'biaya_kemasan' => 'integer',
    ];

    protected function code(): Attribute
    {
        return Attribute::get(fn () => (string) ($this->kode_menu ?: ('MENU-' . str_pad($this->id_menu, 3, '0', STR_PAD_LEFT))));
    }

    protected function name(): Attribute
    {
        return Attribute::get(fn () => $this->nama_menu);
    }

    protected function description(): Attribute
    {
        return Attribute::get(fn () => $this->deskripsi);
    }

    protected function price(): Attribute
    {
        return Attribute::get(fn () => (int) $this->harga);
    }

    protected function isActive(): Attribute
    {
        return Attribute::get(fn () => $this->status === 'tersedia');
    }

    protected function categoriesIdCategorie(): Attribute
    {
        return Attribute::get(fn () => $this->kategori);
    }

    protected function category(): Attribute
    {
        return Attribute::get(fn () => $this->kategori
            ? (object) ['id_categorie' => $this->kategori, 'name' => ucfirst($this->kategori)]
            : null);
    }

    public function ingredients()
    {
        return $this->hasMany(MenuIngredient::class, 'id_menu', 'id_menu');
    }

    // Catatan: pastikan model OrderItem memakai tabel tr_penjualan,
    // kalau tidak, relasi ini akan error saat dipanggil.
    public function orderItems()
    {
        return $this->hasMany(OrderItem::class, 'id_menu', 'id_menu');
    }

    public function stockItems()
    {
        return $this->belongsToMany(
            StockItem::class, 'ms_resep', 'id_menu', 'id_bahan', 'id_menu', 'id_bahan'
        )->withPivot(['id_resep', 'jumlah_bahan', 'jumlah_produk']);
    }

    /**
     * Total kebutuhan semua bahan baku untuk sejumlah porsi menu.
     */
    public function calculateIngredientsRequirement(float|int $portions): array
    {
        return $this->ingredients()->with('stockItem')->get()->map(function ($ingredient) use ($portions) {
            return [
                'stock_item_id'        => $ingredient->id_bahan,
                'stock_item_name'      => $ingredient->stockItem?->name,
                'unit'                 => $ingredient->stockItem?->unit,
                'quantity_per_portion' => $ingredient->quantity_required,
                'total_required'       => $ingredient->calculateRequiredQuantity($portions),
                'current_stock'        => $ingredient->stockItem?->current_stock,
            ];
        })->toArray();
    }
}