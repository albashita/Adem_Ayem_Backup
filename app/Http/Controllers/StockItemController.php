<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\ExpenseItem;
use App\Models\MenuIngredient;
use App\Models\StockItem;
use App\Models\StokKeluar;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StockItemController extends Controller
{
    // Label sumber stok masuk (disimpan di keterangan pengeluaran)
    private const SOURCE_LABEL = [
        'purchase' => 'Pembelian',
        'harvest'  => 'Panen',
        'gift'     => 'Pemberian',
        'other'    => 'Lainnya',
    ];

    // Alasan stok keluar: nilai lama => [enum database, awalan keterangan]
    private const OUT_MAP = [
        'used'    => ['terpakai', null],
        'spoiled' => ['busuk', null],
        'expired' => ['busuk', 'Kadaluarsa'],
        'lost'    => ['lainnya', 'Hilang'],
        'other'   => ['lainnya', null],
    ];

    public function index()
    {
        return view('stok.index', [
            'pageKey' => 'stok',
        ]);
    }

    /**
     * JSON: daftar bahan baku aktif.
     */
    public function data()
    {
        $items = StockItem::where('status', 'aktif')
            ->orderBy('nama_bahan')
            ->get();

        return response()->json($items);
    }

    /**
     * JSON: riwayat pergerakan stok.
     * Masuk  = item pengeluaran kategori "bahan" (tr_pengeluaran).
     * Keluar = ms_stok_keluar.
     */
    public function movements(Request $request)
    {
        $from = $request->input('from');
        $to   = $request->input('to');

        $ins = ExpenseItem::with(['stockItem', 'expense.user'])
            ->whereNotNull('id_bahan')
            ->whereHas('expense', fn ($q) => $q->where('kategori', 'bahan'))
            ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to))
            ->orderByDesc('created_at')
            ->limit(500)
            ->get()
            ->map(function ($m) {
                [$source, $desc] = $this->parseIn($m->expense?->keterangan);

                return [
                    'id'          => 'in-' . $m->id_tr_pengeluaran,
                    'date'        => $m->created_at?->toIso8601String(),
                    'item_name'   => $m->stockItem?->name ?? '-',
                    'unit'        => $m->stockItem?->unit ?? '-',
                    'type'        => 'in',
                    'quantity'    => $m->jumlah,
                    'source_type' => $source,
                    'description' => $desc,
                    'user_name'   => $m->expense?->user?->nama ?? '-',
                ];
            });

        $outs = StokKeluar::with(['stockItem', 'user'])
            ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to))
            ->orderByDesc('created_at')
            ->limit(500)
            ->get()
            ->map(function ($m) {
                [$source, $desc] = $this->parseOut($m);

                return [
                    'id'          => 'out-' . $m->id_stok_keluar,
                    'date'        => $m->created_at?->toIso8601String(),
                    'item_name'   => $m->stockItem?->name ?? '-',
                    'unit'        => $m->stockItem?->unit ?? '-',
                    'type'        => 'out',
                    'quantity'    => $m->jumlah,
                    'source_type' => $source,
                    'description' => $desc,
                    'user_name'   => $m->user?->nama ?? '-',
                ];
            });

        return response()->json(
            $ins->concat($outs)->sortByDesc('date')->take(500)->values()
        );
    }

    /*
    |--------------------------------------------------------------------------
    | CRUD Bahan Baku
    |--------------------------------------------------------------------------
    */

    public function store(Request $request)
    {
        if ($request->has('name')) {
            $request->merge(['name' => trim((string) $request->input('name'))]);
        }

        $validated = $request->validate([
            'name'          => ['required', 'string', 'max:45', Rule::unique('ms_bahan', 'nama_bahan')],
            'unit'          => ['required', Rule::in(StockItem::ALLOWED_UNITS)],
            'minimum_stock' => 'required|integer|min:0',
            'initial_stock' => 'required|integer|min:0',
            'asal_bahan'    => ['nullable', Rule::in(['pembelian', 'budidaya'])],
            'description'   => 'nullable|string|max:150',
        ], [
            'name.unique'   => 'Nama bahan sudah terdaftar. Silakan gunakan nama bahan lain.',
            'name.required' => 'Nama bahan wajib diisi.',
            'name.max'      => 'Nama bahan maksimal 45 karakter.',
            'unit.in'       => 'Satuan harus gram, pcs, atau ekor.',
        ]);

        try {
            $item = DB::transaction(function () use ($validated) {
                $item = StockItem::create([
                    'nama_bahan'   => $validated['name'],
                    'satuan'       => $validated['unit'],
                    'stok'         => $validated['initial_stock'],
                    'stok_minimal' => $validated['minimum_stock'],
                    'asal_bahan'   => $validated['asal_bahan'] ?? 'pembelian',
                    'status'       => 'aktif',
                ]);

                if ($validated['initial_stock'] > 0) {
                    $this->recordStockIn($item, (int) $validated['initial_stock'], 'purchase', 'Stok awal');
                }

                return $item;
            });

            return response()->json([
                'message' => 'Bahan baku berhasil ditambahkan.',
                'item'    => $item,
            ], 201);
        } catch (QueryException $e) {
            if ($e->errorInfo[1] == 1062) {
                return response()->json([
                    'message' => 'Nama bahan sudah terdaftar. Silakan gunakan nama bahan lain.',
                    'errors'  => ['name' => ['Nama bahan sudah terdaftar. Silakan gunakan nama bahan lain.']],
                ], 422);
            }
            throw $e;
        }
    }

    /**
     * Perbarui nama, satuan, dan stok minimum.
     * Jumlah stok diubah lewat stock-in / stock-out.
     */
    public function update(Request $request, StockItem $stockItem)
    {
        if ($request->has('name')) {
            $request->merge(['name' => trim((string) $request->input('name'))]);
        }

        $validated = $request->validate([
            'name'          => ['required', 'string', 'max:45',
                                Rule::unique('ms_bahan', 'nama_bahan')->ignore($stockItem->id_bahan, 'id_bahan')],
            'unit'          => ['required', Rule::in(StockItem::ALLOWED_UNITS)],
            'minimum_stock' => 'required|integer|min:0',
            'description'   => 'nullable|string|max:150',
        ], [
            'name.unique'   => 'Nama bahan sudah terdaftar. Silakan gunakan nama bahan lain.',
            'name.required' => 'Nama bahan wajib diisi.',
            'name.max'      => 'Nama bahan maksimal 45 karakter.',
            'unit.in'       => 'Satuan harus gram, pcs, atau ekor.',
        ]);

        try {
            $stockItem->update([
                'nama_bahan'   => $validated['name'],
                'satuan'       => $validated['unit'],
                'stok_minimal' => $validated['minimum_stock'],
            ]);

            return response()->json([
                'message' => 'Bahan baku berhasil diperbarui.',
                'item'    => $stockItem->fresh(),
            ]);
        } catch (QueryException $e) {
            if ($e->errorInfo[1] == 1062) {
                return response()->json([
                    'message' => 'Nama bahan sudah terdaftar. Silakan gunakan nama bahan lain.',
                    'errors'  => ['name' => ['Nama bahan sudah terdaftar. Silakan gunakan nama bahan lain.']],
                ], 422);
            }
            throw $e;
        }
    }

    /**
     * Ubah status aktif/nonaktif bahan baku.
     */
    public function toggleStatus(Request $request, StockItem $stockItem)
    {
        $validated = $request->validate([
            'is_active' => 'nullable|boolean',
        ]);

        $newStatus = array_key_exists('is_active', $validated)
            ? (bool) $validated['is_active']
            : ! $stockItem->is_active;

        $stockItem->update(['status' => $newStatus ? 'aktif' : 'nonaktif']);

        $stockItem = $stockItem->fresh();

        return response()->json([
            'message'   => $newStatus ? 'Bahan baku berhasil diaktifkan.' : 'Bahan baku berhasil dinonaktifkan.',
            'is_active' => $stockItem->is_active,
            'item'      => $stockItem,
        ]);
    }

    /**
     * Hapus bahan baku.
     * Bahan yang sudah punya riwayat (stok masuk/keluar) atau dipakai di resep
     * tidak dihapus permanen, tetapi diarsipkan (status = nonaktif).
     */
    public function destroy(StockItem $stockItem): JsonResponse
    {
        $hasMovements = $stockItem->stockOuts()->exists() || $stockItem->expenseItems()->exists();
        $hasRecipe    = MenuIngredient::where('id_bahan', $stockItem->id_bahan)->exists();

        if ($hasMovements || $hasRecipe) {
            $stockItem->update(['status' => 'nonaktif']);

            $reasons = [];
            if ($hasMovements) {
                $reasons[] = 'riwayat pergerakan stok';
            }
            if ($hasRecipe) {
                $reasons[] = 'resep menu';
            }

            return response()->json([
                'status'  => 'archived',
                'message' => "Bahan \"{$stockItem->name}\" berhasil dihapus dari daftar bahan aktif dan diarsipkan (karena memiliki " . implode(' dan ', $reasons) . ' yang tetap tersimpan).',
            ]);
        }

        $stockItem->delete();

        return response()->json([
            'status'  => 'deleted',
            'message' => "Bahan \"{$stockItem->name}\" berhasil dihapus secara permanen.",
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Pergerakan Stok
    |--------------------------------------------------------------------------
    */

    /**
     * Tambah stok masuk.
     */
    public function stockIn(Request $request, StockItem $stockItem)
    {
        $validated = $request->validate([
            'quantity'    => 'required|integer|min:1',
            'source_type' => ['required', Rule::in(array_keys(self::SOURCE_LABEL))],
            'description' => 'nullable|string|max:150',
        ]);

        DB::transaction(function () use ($validated, $stockItem) {
            $stockItem->increment('stok', $validated['quantity']);

            $this->recordStockIn(
                $stockItem,
                (int) $validated['quantity'],
                $validated['source_type'],
                $validated['description'] ?? null
            );
        });

        return response()->json([
            'message'       => 'Stok berhasil ditambahkan.',
            'current_stock' => $stockItem->fresh()->current_stock,
        ]);
    }

    /**
     * Kurangi stok (terpakai, busuk/rusak, kadaluarsa, hilang, lainnya).
     */
    public function stockOut(Request $request, StockItem $stockItem)
    {
        $validated = $request->validate([
            'quantity'    => 'required|integer|min:1',
            'source_type' => ['required', Rule::in(array_keys(self::OUT_MAP))],
            'description' => 'nullable|string|max:150|required_if:source_type,other',
        ], [
            'description.required_if' => 'Keterangan wajib diisi untuk alasan "Lainnya".',
        ]);

        [$alasan, $prefix] = self::OUT_MAP[$validated['source_type']];

        $keterangan = trim(($prefix ?? '') . (($prefix && ! empty($validated['description'])) ? ': ' : '') . ($validated['description'] ?? ''));

        try {
            DB::transaction(function () use ($validated, $stockItem, $alasan, $keterangan) {
                // Kunci baris agar dua pengurangan bersamaan tidak membuat stok minus
                $item = StockItem::whereKey($stockItem->id_bahan)->lockForUpdate()->first();

                if ($item->stok < $validated['quantity']) {
                    throw new \RuntimeException(
                        'Stok tidak mencukupi. Stok saat ini: ' . $item->stok . ' ' . $item->satuan . '.'
                    );
                }

                $item->decrement('stok', $validated['quantity']);

                StokKeluar::create([
                    'id_bahan'   => $item->id_bahan,
                    'id_user'    => Auth::id(),
                    'jumlah'     => $validated['quantity'],
                    'alasan'     => $alasan,
                    'keterangan' => $keterangan !== '' ? $keterangan : null,
                ]);
            });
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message'       => 'Stok berhasil dikurangi.',
            'current_stock' => $stockItem->fresh()->current_stock,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper
    |--------------------------------------------------------------------------
    */

    /**
     * Catat stok masuk sebagai pengeluaran kategori "bahan" bernilai 0.
     * Pembelian bahan dengan harga diinput lewat halaman Pengeluaran.
     */
    private function recordStockIn(StockItem $item, int $qty, string $source, ?string $desc): void
    {
        $keterangan = 'Stok masuk (' . self::SOURCE_LABEL[$source] . ')' . ($desc ? ': ' . $desc : '');

        $expense = Expense::create([
            'id_user'    => Auth::id(),
            'tanggal'    => now()->toDateString(),
            'total'      => 0,
            'kategori'   => 'bahan',
            'keterangan' => $keterangan,
        ]);

        ExpenseItem::create([
            'id_pengeluaran' => $expense->id_pengeluaran,
            'id_bahan'       => $item->id_bahan,
            'jumlah'         => $qty,
            'harga'          => 0,
            'sub_total'      => 0,
        ]);
    }

    private function parseIn(?string $text): array
    {
        $source = 'purchase';
        $desc   = $text ?? '';

        if (preg_match('/^Stok masuk \((.+?)\)(?::\s*(.*))?$/', $desc, $m)) {
            $found  = array_search($m[1], self::SOURCE_LABEL, true);
            $source = $found !== false ? $found : 'other';
            $desc   = $m[2] ?? '';
        }

        return [$source, $desc];
    }

    private function parseOut(StokKeluar $m): array
    {
        $text   = $m->keterangan ?? '';
        $source = match ($m->alasan) {
            'terpakai'      => 'used',
            'busuk', 'rusak' => 'spoiled',
            default         => 'other',
        };

        foreach (['expired' => 'Kadaluarsa', 'lost' => 'Hilang'] as $key => $label) {
            if (str_starts_with($text, $label)) {
                $source = $key;
                $text   = ltrim(substr($text, strlen($label)), ': ');
                break;
            }
        }

        return [$source, $text];
    }
}