<?php

namespace App\Http\Controllers;

use App\Models\Menu;
use App\Models\MenuIngredient;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    // Metode bayar di aplikasi -> enum ms_penjualan.jenis_pembayaran
    private const METHOD_TO_DB = [
        'cash'     => 'tunai',
        'qris'     => 'qris',
        'transfer' => 'transfer',
    ];

    private const CANCEL_REASONS = [
        'customer_cancel'  => 'Pelanggan batal',
        'wrong_input'      => 'Salah input',
        'menu_unavailable' => 'Menu habis',
        'other'            => 'Lainnya',
    ];

    private const REFUND_LABELS = [
        'cash'     => 'tunai',
        'transfer' => 'transfer/QRIS',
        'none'     => 'tidak dikembalikan',
    ];

    /**
     * Simpan pesanan baru dari kasir dengan status pending.
     * Ketersediaan stok bahan baku divalidasi di awal.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'customer_name'   => 'nullable|string|max:30',
            'service_type'    => 'required|in:dine-in,takeaway',
            'discount'        => 'nullable|integer|min:0',
            'items'           => 'required|array|min:1',
            'items.*.menu_id' => 'required|integer|exists:ms_menu,id_menu',
            'items.*.qty'     => 'required|integer|min:1|max:99',
        ]);

        // Gabungkan baris menu yang sama
        $qtyByMenu = [];
        foreach ($data['items'] as $item) {
            $id = (int) $item['menu_id'];
            $qtyByMenu[$id] = ($qtyByMenu[$id] ?? 0) + (int) $item['qty'];
        }

        $menus = Menu::whereIn('id_menu', array_keys($qtyByMenu))->get()->keyBy('id_menu');

        foreach ($menus as $menu) {
            if ($menu->status !== 'tersedia') {
                return response()->json([
                    'message' => "Menu '{$menu->name}' sedang tidak tersedia.",
                ], 422);
            }
        }

        $error = $this->stockError($this->stockRequirements($qtyByMenu));
        if ($error) {
            return response()->json(['message' => $error], 422);
        }

        $order = DB::transaction(function () use ($data, $qtyByMenu, $menus) {
            $subtotal = 0;
            $rows = [];

            foreach ($qtyByMenu as $menuId => $qty) {
                $menu = $menus[$menuId];
                $line = $menu->price * $qty;
                $subtotal += $line;

                $rows[] = [
                    'id_menu'   => $menuId,
                    'jumlah'    => $qty,
                    'harga'     => $menu->price,
                    'sub_total' => $line,
                ];
            }

            $discount = min((int) ($data['discount'] ?? 0), $subtotal);

            // Nomor nota per hari: INV-20261115-0001
            $prefix = 'INV-' . now()->format('Ymd') . '-';
            $last = Order::where('no_nota', 'like', $prefix . '%')
                ->lockForUpdate()
                ->orderByDesc('no_nota')
                ->value('no_nota');
            $seq = $last ? ((int) substr($last, -4)) + 1 : 1;

            $order = Order::create([
                'id_user'          => Auth::id(),
                'tanggal'          => now(),
                'sub_total'        => $subtotal,
                'diskon'           => $discount,
                'total'            => $subtotal - $discount,
                'jenis_pembayaran' => null, // diisi saat dibayar
                'status'           => 'pending',
                'nama_pelanggan'   => $data['customer_name'] ?: 'Umum',
                'jenis_layanan'    => $data['service_type'],
                'no_nota'          => $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT),
            ]);

            $order->items()->createMany($rows);

            return $order;
        });

        return response()->json([
            'message'        => 'Pesanan tersimpan.',
            'order_no'       => $order->no_nota,
            'invoice_number' => $order->no_nota,
            'id'             => $order->id_penjualan,
        ], 201);
    }

    /**
     * Riwayat transaksi untuk kasir.
     */
    public function history()
    {
        $orders = Order::with(['items.menu', 'user'])
            ->orderByDesc('tanggal')
            ->limit(200)
            ->get();

        return response()->json($orders->map(function ($o) {
            $isPaid = $o->status !== 'pending' && $o->jenis_pembayaran;
            $method = $isPaid ? $o->payment->payment_method : null; // cash / qris / transfer
            $received = null;
            $change = 0;

            if ($isPaid) {
                $received = $method === 'cash'
                    ? (int) ($o->uang_diterima ?? $o->total)
                    : (int) $o->total;
                $change = max(0, $received - (int) $o->total);
            }

            return [
                'id'             => $o->id_penjualan,
                'no'             => $o->no_nota,
                'date'           => $o->tanggal->toIso8601String(),
                'customer'       => $o->nama_pelanggan,
                'service_type'   => $o->jenis_layanan,
                'subtotal'       => $o->sub_total,
                'discount'       => $o->diskon,
                'tax'            => 0,
                'total'          => $o->total,
                'status'         => $o->status,
                'cancelled_at'   => $o->status === 'cancelled' ? $o->updated_at?->toIso8601String() : null,
                'cancel_text'    => $o->alasan_batal,
                'payment_method' => $method,
                'amount_paid'    => $isPaid ? (int) $o->total : null,
                'received'       => $received,
                'change'         => $change,
                'paid_at'        => $o->status === 'completed' ? $o->updated_at?->toIso8601String() : null,
                'user'           => $o->user ? [
                    'id'   => $o->user->id_user,
                    'name' => $o->user->name,
                    'role' => $o->user->role,
                ] : null,
                'items'          => $o->items->map(fn ($i) => [
                    'menu_id' => $i->id_menu,
                    'name'    => $i->menu?->name ?? '-',
                    'qty'     => $i->jumlah,
                    'price'   => $i->harga,
                ])->values(),
            ];
        })->values());
    }

    /**
     * Ubah pesanan yang belum dibayar (menu, jumlah, nama, tipe layanan, diskon).
     */
    public function update(Request $request, $id)
    {
        $data = $request->validate([
            'customer_name'   => 'nullable|string|max:30',
            'service_type'    => 'required|in:dine-in,takeaway',
            'discount'        => 'nullable|integer|min:0',
            'items'           => 'required|array|min:1',
            'items.*.menu_id' => 'required|integer|exists:ms_menu,id_menu',
            'items.*.qty'     => 'required|integer|min:1|max:99',
        ], [
            'items.required' => 'Pesanan harus memiliki minimal 1 menu.',
            'items.min'      => 'Pesanan harus memiliki minimal 1 menu.',
        ]);

        // Gabungkan baris menu yang sama
        $qtyByMenu = [];
        foreach ($data['items'] as $item) {
            $menuId = (int) $item['menu_id'];
            $qtyByMenu[$menuId] = ($qtyByMenu[$menuId] ?? 0) + (int) $item['qty'];
        }

        $menus = Menu::whereIn('id_menu', array_keys($qtyByMenu))->get()->keyBy('id_menu');

        foreach ($menus as $menu) {
            if ($menu->status !== 'tersedia') {
                return response()->json([
                    'message' => "Menu '{$menu->name}' sedang tidak tersedia.",
                ], 422);
            }
        }

        $error = $this->stockError($this->stockRequirements($qtyByMenu));
        if ($error) {
            return response()->json(['message' => $error], 422);
        }

        $result = DB::transaction(function () use ($id, $data, $qtyByMenu, $menus) {
            // Kunci pesanan supaya tidak berubah bersamaan dengan pembayaran
            $order = Order::lockForUpdate()->findOrFail($id);

            if ($order->status !== 'pending') {
                return ['ok' => false, 'message' => 'Hanya pesanan yang belum dibayar yang dapat diubah.'];
            }

            $subtotal = 0;
            $rows = [];

            foreach ($qtyByMenu as $menuId => $qty) {
                $menu = $menus[$menuId];
                $line = $menu->price * $qty;
                $subtotal += $line;

                $rows[] = [
                    'id_menu'   => $menuId,
                    'jumlah'    => $qty,
                    'harga'     => $menu->price,
                    'sub_total' => $line,
                ];
            }

            $discount = min((int) ($data['discount'] ?? 0), $subtotal);

            $order->items()->delete();
            $order->items()->createMany($rows);

            $order->update([
                'sub_total'      => $subtotal,
                'diskon'         => $discount,
                'total'          => $subtotal - $discount,
                'nama_pelanggan' => $data['customer_name'] ?: 'Umum',
                'jenis_layanan'  => $data['service_type'],
            ]);

            return ['ok' => true, 'message' => 'Pesanan berhasil diubah.'];
        });

        if (! $result['ok']) {
            return response()->json(['message' => $result['message']], 422);
        }

        return response()->json(['message' => $result['message']]);
    }

    /**
     * Konfirmasi pembayaran dan kurangi stok bahan baku secara otomatis.
     * Semuanya dalam satu transaksi database dengan lockForUpdate.
     */
    public function pay(Request $request, $id)
    {
        $data = $request->validate([
            'payment_method' => 'required|in:cash,transfer,qris',
            'received'       => 'nullable|integer|min:0',
        ]);

        $result = DB::transaction(function () use ($id, $data) {
            // Kunci pesanan supaya tidak terbayar dua kali
            $order = Order::with('items')->lockForUpdate()->findOrFail($id);

            if ($order->status !== 'pending') {
                return ['ok' => false, 'message' => 'Pesanan ini sudah dibayar atau dibatalkan.'];
            }

            $isCash = $data['payment_method'] === 'cash';
            $received = $isCash ? (int) ($data['received'] ?? 0) : (int) $order->total;

            if ($isCash && $received < $order->total) {
                return ['ok' => false, 'message' => 'Uang diterima kurang dari total tagihan.'];
            }

            // Hitung kebutuhan bahan dari resep, kunci baris bahan, lalu cek stok
            $qtyByMenu = $order->items
                ->groupBy('id_menu')
                ->map(fn ($rows) => (int) $rows->sum('jumlah'))
                ->all();

            $requirements = $this->stockRequirements($qtyByMenu, true);
            $error = $this->stockError($requirements);

            if ($error) {
                return ['ok' => false, 'message' => $error];
            }

            // Kurangi stok dan catat sebagai stok keluar (alasan: terpakai)
            foreach ($requirements as $bahanId => $r) {
                if ($r['needed'] <= 0) {
                    continue;
                }

                DB::table('ms_bahan')
                    ->where('id_bahan', $bahanId)
                    ->decrement('stok', $r['needed'], ['updated_at' => now()]);

                DB::table('ms_stok_keluar')->insert([
                    'id_bahan'   => $bahanId,
                    'id_user'    => Auth::id() ?: $order->id_user,
                    'jumlah'     => $r['needed'],
                    'alasan'     => 'terpakai',
                    'keterangan' => "Penjualan: {$order->no_nota}",
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $order->update([
                'jenis_pembayaran' => self::METHOD_TO_DB[$data['payment_method']],
                'uang_diterima'    => $received,
                'status'           => 'completed',
                'receipt_token'    => $order->receipt_token ?: Str::random(16),
            ]);

            return ['ok' => true, 'message' => 'Pembayaran berhasil.'];
        });

        if (! $result['ok']) {
            return response()->json(['message' => $result['message']], 422);
        }

        return response()->json(['message' => $result['message']]);
    }

    /**
     * Batalkan pesanan yang sudah dibayar.
     * Data pembayaran tetap tersimpan; status menjadi 'cancelled'.
     * Stok (opsional) dikembalikan dari catatan stok keluar transaksi ini.
     */
    public function cancel(Request $request, $id)
    {
        $data = $request->validate([
            'reason'        => 'required|in:customer_cancel,wrong_input,menu_unavailable,other',
            'note'          => 'nullable|string|max:150|required_if:reason,other',
            'refund_method' => 'required|in:cash,transfer,none',
            'restore_stock' => 'required|boolean',
        ], [
            'note.required_if' => 'Keterangan wajib diisi untuk alasan "Lainnya".',
        ]);

        $result = DB::transaction(function () use ($id, $data) {
            $order = Order::lockForUpdate()->findOrFail($id);

            if ($order->status !== 'completed') {
                return ['ok' => false, 'message' => 'Hanya pesanan yang sudah dibayar yang dapat dibatalkan.'];
            }

            if ($data['restore_stock']) {
                $moves = DB::table('ms_stok_keluar')
                    ->where('keterangan', "Penjualan: {$order->no_nota}")
                    ->where('alasan', 'terpakai')
                    ->lockForUpdate()
                    ->get();

                foreach ($moves as $m) {
                    DB::table('ms_bahan')
                        ->where('id_bahan', $m->id_bahan)
                        ->increment('stok', (int) $m->jumlah, ['updated_at' => now()]);
                }

                // Pengurangan stok dibatalkan, jadi catatannya ikut dihapus
                if ($moves->isNotEmpty()) {
                    DB::table('ms_stok_keluar')
                        ->whereIn('id_stok_keluar', $moves->pluck('id_stok_keluar')->all())
                        ->delete();
                }
            }

            $order->update([
                'status'       => 'cancelled',
                'alasan_batal' => $this->cancelText($data),
            ]);

            return ['ok' => true, 'message' => 'Pesanan berhasil dibatalkan.'];
        });

        if (! $result['ok']) {
            return response()->json(['message' => $result['message']], 422);
        }

        return response()->json(['message' => $result['message']]);
    }

    /* ------------------------------------------------------------ HELPER */

    /**
     * Kebutuhan bahan baku untuk sekumpulan menu: [id_menu => jumlah porsi].
     * Hasil: [id_bahan => ['name','unit','stock','active','needed']].
     * Menu tanpa resep dilewati. Stok bertipe bilangan bulat, jadi kebutuhan dibulatkan ke atas.
     */
    private function stockRequirements(array $qtyByMenu, bool $lock = false): array
    {
        if (! $qtyByMenu) {
            return [];
        }

        $ingredients = MenuIngredient::whereIn('id_menu', array_keys($qtyByMenu))->get();
        if ($ingredients->isEmpty()) {
            return [];
        }

        $query = DB::table('ms_bahan')
            ->whereIn('id_bahan', $ingredients->pluck('id_bahan')->unique()->all());

        if ($lock) {
            $query->lockForUpdate();
        }

        $bahan = $query->get()->keyBy('id_bahan');

        $req = [];
        foreach ($ingredients as $ing) {
            $row = $bahan->get($ing->id_bahan);
            if (! $row) {
                continue;
            }

            $id = (int) $ing->id_bahan;
            $req[$id] ??= [
                'name'   => $row->nama_bahan,
                'unit'   => $row->satuan,
                'stock'  => (int) $row->stok,
                'active' => $row->status === 'aktif',
                'needed' => 0.0,
            ];

            $req[$id]['needed'] += (float) $ing->quantity_required * ($qtyByMenu[$ing->id_menu] ?? 0);
        }

        foreach ($req as &$r) {
            // round() mencegah 1.0000000000000002 menjadi 2
            $r['needed'] = (int) ceil(round($r['needed'], 6));
        }
        unset($r);

        return $req;
    }

    // Pesan error pertama yang ditemukan, atau null kalau stok cukup
    private function stockError(array $requirements): ?string
    {
        foreach ($requirements as $r) {
            if (! $r['active']) {
                return "Bahan baku '{$r['name']}' sudah dinonaktifkan, pesanan tidak dapat diproses.";
            }

            if ($r['stock'] < $r['needed']) {
                return "Stok bahan baku '{$r['name']}' tidak mencukupi (tersedia: {$r['stock']} {$r['unit']}, dibutuhkan: {$r['needed']} {$r['unit']}).";
            }
        }

        return null;
    }

    // Gabungan alasan, catatan, refund, dan status stok untuk kolom alasan_batal
    private function cancelText(array $data): string
    {
        $text = self::CANCEL_REASONS[$data['reason']];

        if (! empty($data['note'])) {
            $text .= ': ' . $data['note'];
        }

        $text .= ' | Refund: ' . self::REFUND_LABELS[$data['refund_method']];
        $text .= ' | Stok: ' . ($data['restore_stock'] ? 'dikembalikan' : 'tidak dikembalikan');

        return Str::limit($text, 250, '');
    }
}