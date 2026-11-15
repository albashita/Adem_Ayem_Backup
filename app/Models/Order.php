<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $table = 'ms_penjualan';
    protected $primaryKey = 'id_penjualan';

    protected $fillable = [
        'id_user', 'tanggal', 'sub_total', 'diskon', 'total',
        'jenis_pembayaran', 'status', 'nama_pelanggan', 'jenis_layanan',
        'no_nota', 'alasan_batal', 'uang_diterima', 'receipt_token',
    ];

    protected $casts = [
        'tanggal'       => 'datetime',
        'sub_total'     => 'integer',
        'diskon'        => 'integer',
        'total'         => 'integer',
        'uang_diterima' => 'integer',
    ];

    // Nama atribut lama, supaya view struk tetap jalan.
    // PENTING: accessor yang namanya sama dengan kolom (subtotal vs sub_total)
    // harus membaca dari $attributes, bukan dari $this->sub_total, kalau tidak error.
    protected function idOrder(): Attribute
    {
        return Attribute::get(fn () => $this->id_penjualan);
    }

    protected function invoiceNumber(): Attribute
    {
        return Attribute::get(fn () => $this->no_nota);
    }

    protected function orderDate(): Attribute
    {
        return Attribute::get(fn () => $this->tanggal);
    }

    protected function subtotal(): Attribute
    {
        return Attribute::get(fn ($value, array $attributes) => (int) ($attributes['sub_total'] ?? 0));
    }

    protected function discount(): Attribute
    {
        return Attribute::get(fn () => $this->diskon);
    }

    protected function tax(): Attribute
    {
        return Attribute::get(fn () => 0);
    }

    protected function customerName(): Attribute
    {
        return Attribute::get(fn () => $this->nama_pelanggan);
    }

    protected function serviceType(): Attribute
    {
        return Attribute::get(fn () => $this->jenis_layanan);
    }

    // Pembayaran digabung di ms_penjualan; objek ini meniru tabel payments yang lama.
    // Ini accessor, bukan relasi, jadi jangan dipakai di with().
    protected function payment(): Attribute
    {
        return Attribute::get(function () {
            if (! $this->jenis_pembayaran) {
                return null;
            }

            return (object) [
                'payment_method'   => $this->jenis_pembayaran === 'tunai' ? 'cash' : $this->jenis_pembayaran,
                'amount'           => $this->total,
                'paid_at'          => $this->updated_at,
                'reference_number' => null,
                'notes'            => null,
            ];
        });
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class, 'id_penjualan', 'id_penjualan');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'id_user', 'id_user');
    }
}