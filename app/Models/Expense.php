<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    protected $table = 'ms_pengeluaran';
    protected $primaryKey = 'id_pengeluaran';

    public const CATEGORIES = [
        'bahan', 'wifi', 'gaji', 'listrik',
        'operasional', 'transportasi', 'perawatan', 'lain-lain',
    ];

    protected $fillable = ['id_user', 'tanggal', 'total', 'kategori', 'keterangan'];

    protected $casts = [
        'tanggal' => 'date:Y-m-d',
        'total'   => 'integer',
    ];

    public function items()
    {
        return $this->hasMany(ExpenseItem::class, 'id_pengeluaran', 'id_pengeluaran');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'id_user', 'id_user');
    }
}