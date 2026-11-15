<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    protected $table = 'tr_penjualan';
    protected $primaryKey = 'id_tr_penjualan';

    protected $fillable = ['id_penjualan', 'id_menu', 'jumlah', 'harga', 'sub_total'];

    protected $casts = [
        'jumlah'    => 'integer',
        'harga'     => 'integer',
        'sub_total' => 'integer',
    ];

    protected function quantity(): Attribute
    {
        return Attribute::get(fn () => $this->jumlah);
    }

    protected function price(): Attribute
    {
        return Attribute::get(fn () => $this->harga);
    }

    // Membaca dari $attributes karena nama accessor sama dengan kolom sub_total
    protected function subtotal(): Attribute
    {
        return Attribute::get(fn ($value, array $attributes) => (int) ($attributes['sub_total'] ?? 0));
    }

    public function menu()
    {
        return $this->belongsTo(Menu::class, 'id_menu', 'id_menu');
    }

    public function order()
    {
        return $this->belongsTo(Order::class, 'id_penjualan', 'id_penjualan');
    }
}