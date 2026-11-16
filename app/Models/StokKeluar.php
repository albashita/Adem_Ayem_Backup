<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StokKeluar extends Model
{
    protected $table = 'ms_stok_keluar';
    protected $primaryKey = 'id_stok_keluar';
    protected $guarded = [];

    public function stockItem()
    {
        return $this->belongsTo(StockItem::class, 'id_bahan', 'id_bahan');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'id_user', 'id_user');
    }
}