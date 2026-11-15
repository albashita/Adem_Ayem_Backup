<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    // Status penjualan yang dihitung sebagai pemasukan
    private const DONE = 'completed';

    private const METHOD_LABELS = [
        'tunai'    => 'Tunai',
        'qris'     => 'QRIS',
        'transfer' => 'Transfer Bank',
    ];

    // enum ms_pengeluaran.kategori -> label tampilan
    private const CATEGORY_LABELS = [
        'bahan'        => 'Bahan Baku',
        'wifi'         => 'WiFi',
        'gaji'         => 'Gaji',
        'listrik'      => 'Listrik',
        'operasional'  => 'Operasional',
        'transportasi' => 'Transportasi',
        'perawatan'    => 'Perawatan',
        'lain-lain'    => 'Lain-lain',
    ];

    public function index()
    {
        $isAdmin = auth()->user()->role === 'admin';
        $today = Carbon::today();

        $periods = [
            'today' => [$today->copy()->startOfDay(), $today->copy()->endOfDay()],
            'w'     => [$today->copy()->subDays(6)->startOfDay(), $today->copy()->endOfDay()],
            'm'     => [$today->copy()->startOfMonth(), $today->copy()->endOfDay()],
        ];

        $stats = [];
        foreach ($periods as $key => [$start, $end]) {
            $stats[$key] = $isAdmin
                ? $this->adminPeriod($start, $end)
                : $this->ownerPeriod($start, $end);
        }

        return view('dashboard.index', [
            'pageKey'    => 'dashboard',
            'isAdmin'    => $isAdmin,
            'stats'      => $stats,
            'chart'      => $this->chart7Days(),
            'lowStock'   => $this->lowStock(),
            'stockCount' => DB::table('ms_bahan')->where('status', 'aktif')->count(),
            'feed'       => $isAdmin ? $this->movements(8) : $this->activity(),
        ]);
    }

    /* ------------------------------------------------------------ OWNER */

    private function ownerPeriod(Carbon $s, Carbon $e): array
    {
        [$ps, $pe] = $this->previousRange($s, $e);

        $income  = $this->income($s, $e);
        $expense = $this->expense($s, $e);

        $pending = fn () => DB::table('ms_penjualan')
            ->where('status', 'pending')
            ->whereBetween('tanggal', [$s, $e]);

        return [
            'income'       => $income,
            'expense'      => $expense,
            'net'          => $income - $expense,
            'trx'          => DB::table('ms_penjualan')
                                ->where('status', self::DONE)
                                ->whereBetween('tanggal', [$s, $e])
                                ->count(),
            'pending'      => $pending()->count(),
            'pendingTotal' => (float) $pending()->sum('total'),
            'prevIncome'   => $this->income($ps, $pe),
            'prevExpense'  => $this->expense($ps, $pe),
            'methods'      => $this->paymentMethods($s, $e),
            'best'         => $this->bestSellers($s, $e),
        ];
    }

    private function paymentMethods(Carbon $s, Carbon $e): array
    {
        $rows = DB::table('ms_penjualan')
            ->where('status', self::DONE)
            ->whereBetween('tanggal', [$s, $e])
            ->selectRaw('jenis_pembayaran as m, COUNT(*) as c, SUM(total) as t')
            ->groupBy('jenis_pembayaran')
            ->get()
            ->keyBy('m');

        $result = [];
        foreach (self::METHOD_LABELS as $key => $label) {
            $result[] = [
                'label' => $label,
                'count' => (int) ($rows[$key]->c ?? 0),
                'total' => (float) ($rows[$key]->t ?? 0),
            ];
        }

        // metode lain yang tidak dikenal tetap ditampilkan
        foreach ($rows as $key => $row) {
            if (! isset(self::METHOD_LABELS[$key])) {
                $result[] = ['label' => ucfirst($key), 'count' => (int) $row->c, 'total' => (float) $row->t];
            }
        }

        return $result;
    }

    private function bestSellers(Carbon $s, Carbon $e): array
    {
        return DB::table('tr_penjualan as t')
            ->join('ms_penjualan as p', 'p.id_penjualan', '=', 't.id_penjualan')
            ->join('ms_menu as m', 'm.id_menu', '=', 't.id_menu')
            ->where('p.status', self::DONE)
            ->whereBetween('p.tanggal', [$s, $e])
            ->selectRaw('m.nama_menu as name, SUM(t.jumlah) as qty')
            ->groupBy('m.id_menu', 'm.nama_menu')
            ->orderByDesc('qty')
            ->limit(5)
            ->get()
            ->map(fn ($r) => ['name' => $r->name, 'qty' => (int) $r->qty])
            ->all();
    }

    private function activity(): array
    {
        $moves = collect($this->movements(5))->map(fn ($m) => [
            'title' => ($m['type'] === 'in' ? 'Stok masuk: ' : 'Stok keluar: ') . $m['name'] . ' ' . $m['qty'] . ' ' . $m['unit'],
            'sub'   => $m['desc'],
            'time'  => $m['time'],
            'at'    => $m['at'],
        ]);

        $expenses = DB::table('ms_pengeluaran')
            ->orderByDesc('created_at')
            ->limit(3)
            ->get()
            ->map(fn ($x) => [
                'title' => 'Pengeluaran Rp ' . number_format($x->total, 0, ',', '.'),
                'sub'   => trim($this->categoryLabel($x->kategori) . ($x->keterangan ? ' · ' . $x->keterangan : '')),
                'time'  => Carbon::parse($x->created_at)->locale('id')->diffForHumans(),
                'at'    => Carbon::parse($x->created_at)->toIso8601String(),
            ]);

        return $moves->concat($expenses)->sortByDesc('at')->take(6)->values()->all();
    }

    /* ------------------------------------------------------------ ADMIN */

    private function adminPeriod(Carbon $s, Carbon $e): array
    {
        $dates = [$s->toDateString(), $e->toDateString()];

        $buy = fn () => DB::table('ms_pengeluaran')
            ->where('kategori', 'bahan')
            ->whereBetween('tanggal', $dates);

        $byCategory = DB::table('ms_pengeluaran')
            ->whereBetween('tanggal', $dates)
            ->selectRaw('kategori, SUM(total) as t')
            ->groupBy('kategori')
            ->orderByDesc('t')
            ->get()
            ->map(fn ($r) => ['label' => $this->categoryLabel($r->kategori), 'total' => (float) $r->t])
            ->all();

        return [
            // stok masuk = item pembelian bahan, stok keluar = pengurangan stok
            'masuk'      => DB::table('tr_pengeluaran')
                                ->whereNotNull('id_bahan')
                                ->whereBetween('created_at', [$s, $e])
                                ->count(),
            'keluar'     => DB::table('ms_stok_keluar')
                                ->whereBetween('created_at', [$s, $e])
                                ->count(),
            'buyTotal'   => (float) $buy()->sum('total'),
            'buyCount'   => $buy()->count(),
            'spend'      => $this->expense($s, $e),
            'byCategory' => $byCategory,
        ];
    }

    private function movements(int $limit): array
    {
        $in = DB::table('tr_pengeluaran as t')
            ->join('ms_bahan as b', 'b.id_bahan', '=', 't.id_bahan')
            ->join('ms_pengeluaran as p', 'p.id_pengeluaran', '=', 't.id_pengeluaran')
            ->orderByDesc('t.created_at')
            ->limit($limit)
            ->get(['t.jumlah', 't.created_at', 'p.keterangan', 'b.nama_bahan', 'b.satuan'])
            ->map(fn ($r) => [
                'type' => 'in',
                'name' => $r->nama_bahan,
                'qty'  => (float) $r->jumlah,
                'unit' => $r->satuan,
                'desc' => $r->keterangan ?: 'Pembelian',
                'time' => Carbon::parse($r->created_at)->locale('id')->diffForHumans(),
                'at'   => Carbon::parse($r->created_at)->toIso8601String(),
            ]);

        $out = DB::table('ms_stok_keluar as k')
            ->join('ms_bahan as b', 'b.id_bahan', '=', 'k.id_bahan')
            ->orderByDesc('k.created_at')
            ->limit($limit)
            ->get(['k.jumlah', 'k.alasan', 'k.keterangan', 'k.created_at', 'b.nama_bahan', 'b.satuan'])
            ->map(fn ($r) => [
                'type' => 'out',
                'name' => $r->nama_bahan,
                'qty'  => (float) $r->jumlah,
                'unit' => $r->satuan,
                'desc' => $r->keterangan ?: ucfirst($r->alasan),
                'time' => Carbon::parse($r->created_at)->locale('id')->diffForHumans(),
                'at'   => Carbon::parse($r->created_at)->toIso8601String(),
            ]);

        return $in->concat($out)->sortByDesc('at')->take($limit)->values()->all();
    }

    /* ----------------------------------------------------------- SHARED */

    private function lowStock(): array
    {
        return DB::table('ms_bahan')
            ->where('status', 'aktif')
            ->whereColumn('stok', '<=', 'stok_minimal')
            ->orderBy('stok')
            ->get()
            ->map(fn ($i) => [
                'name' => $i->nama_bahan,
                'unit' => $i->satuan,
                'cur'  => (float) $i->stok,
                'min'  => (float) $i->stok_minimal,
            ])
            ->all();
    }

    private function chart7Days(): array
    {
        $names = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
        $out = ['days' => [], 'income' => [], 'expense' => []];

        for ($i = 6; $i >= 0; $i--) {
            $d = Carbon::today()->subDays($i);
            $out['days'][]    = $names[(int) $d->dayOfWeek];
            $out['income'][]  = $this->income($d->copy()->startOfDay(), $d->copy()->endOfDay());
            $out['expense'][] = $this->expense($d->copy()->startOfDay(), $d->copy()->endOfDay());
        }

        return $out;
    }

    private function income(Carbon $s, Carbon $e): float
    {
        return (float) DB::table('ms_penjualan')
            ->where('status', self::DONE)
            ->whereBetween('tanggal', [$s, $e])
            ->sum('total');
    }

    private function expense(Carbon $s, Carbon $e): float
    {
        return (float) DB::table('ms_pengeluaran')
            ->whereBetween('tanggal', [$s->toDateString(), $e->toDateString()])
            ->sum('total');
    }

    private function previousRange(Carbon $s, Carbon $e): array
    {
        $len = (int) floor($s->diffInDays($e)) + 1;

        return [$s->copy()->subDays($len), $s->copy()->subDay()->endOfDay()];
    }

    private function categoryLabel(?string $key): string
    {
        return self::CATEGORY_LABELS[$key] ?? ($key ? ucfirst($key) : 'Lainnya');
    }
}