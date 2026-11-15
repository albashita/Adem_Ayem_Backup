<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExpenseItem extends Model
{
    // Di tabel ini kolomnya bernama "updated", bukan "updated_at"
    const UPDATED_AT = 'updated';

    protected $table = 'tr_pengeluaran';
    protected $primaryKey = 'id_tr_pengeluaran';

    protected $fillable = ['id_pengeluaran', 'id_bahan', 'jumlah', 'harga', 'sub_total'];

    public function expense()
    {
        return $this->belongsTo(Expense::class, 'id_pengeluaran', 'id_pengeluaran');
    }

    public function stockItem()
    {
        return $this->belongsTo(StockItem::class, 'id_bahan', 'id_bahan');
    }
}