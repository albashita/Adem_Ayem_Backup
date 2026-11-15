<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class StockItem extends Model
{
    public const ALLOWED_UNITS = ['gram', 'pcs', 'ekor'];

    protected $table = 'ms_bahan';
    protected $primaryKey = 'id_bahan';

    protected $fillable = [
        'nama_bahan', 'stok', 'satuan', 'asal_bahan', 'stok_minimal', 'status',
    ];

    protected $casts = [
        'stok'         => 'integer',
        'stok_minimal' => 'integer',
    ];

    // Nama atribut lama tetap tersedia agar JSON ke JavaScript tidak berubah
    protected $appends = [
        'id_stock_item', 'name', 'unit', 'current_stock',
        'minimum_stock', 'is_active', 'description',
    ];

    protected function idStockItem(): Attribute
    {
        return Attribute::get(fn () => $this->id_bahan);
    }

    protected function name(): Attribute
    {
        return Attribute::get(fn () => $this->nama_bahan);
    }

    protected function unit(): Attribute
    {
        return Attribute::get(fn () => $this->satuan);
    }

    protected function currentStock(): Attribute
    {
        return Attribute::get(fn () => (int) $this->stok);
    }

    protected function minimumStock(): Attribute
    {
        return Attribute::get(fn () => (int) $this->stok_minimal);
    }

    protected function isActive(): Attribute
    {
        return Attribute::get(fn () => $this->status === 'aktif');
    }

    // ms_bahan tidak punya kolom deskripsi
    protected function description(): Attribute
    {
        return Attribute::get(fn () => null);
    }

    public function stockOuts()
    {
        return $this->hasMany(StockOut::class, 'id_bahan', 'id_bahan');
    }

    public function expenseItems()
    {
        return $this->hasMany(ExpenseItem::class, 'id_bahan', 'id_bahan');
    }

    public function menuIngredients()
    {
        return $this->hasMany(MenuIngredient::class, 'id_bahan', 'id_bahan');
    }

    public function menus()
    {
        return $this->belongsToMany(
            Menu::class, 'ms_resep', 'id_bahan', 'id_menu', 'id_bahan', 'id_menu'
        )->withPivot(['id_resep', 'jumlah_bahan', 'jumlah_produk']);
    }
}