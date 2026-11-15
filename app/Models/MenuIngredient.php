<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class MenuIngredient extends Model
{
    protected $table = 'ms_resep';
    protected $primaryKey = 'id_resep';

    protected $fillable = ['id_bahan', 'id_menu', 'jumlah_bahan', 'jumlah_produk'];

    /**
     * Kebutuhan bahan per 1 porsi = jumlah_bahan / jumlah_produk.
     */
    protected function quantityRequired(): Attribute
    {
        return Attribute::get(fn () => $this->jumlah_produk > 0
            ? (float) $this->jumlah_bahan / (float) $this->jumlah_produk
            : 0.0);
    }

    /**
     * Ubah angka per porsi (boleh desimal) menjadi pasangan integer
     * [jumlah_bahan, jumlah_produk]. Contoh: 0.5 => [1, 2].
     */
    public static function splitPerPortion(float $qty): array
    {
        for ($p = 1; $p <= 1000; $p++) {
            $b = $qty * $p;
            if ($b >= 1 && abs($b - round($b)) < 1e-6) {
                return [(int) round($b), $p];
            }
        }

        return [max(1, (int) round($qty)), 1];
    }

    public function menu()
    {
        return $this->belongsTo(Menu::class, 'id_menu', 'id_menu');
    }

    public function stockItem()
    {
        return $this->belongsTo(StockItem::class, 'id_bahan', 'id_bahan');
    }

    public function calculateRequiredQuantity(float|int $portions): float|int
    {
        return $this->quantity_required * $portions;
    }
}