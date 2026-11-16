<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ExpenseController extends Controller
{
    /**
     * Memeriksa hak akses bahwa hanya Admin yang dapat mengakses controller ini.
     */
    private function checkAdminAccess(): void
    {
        if (Auth::user()?->role !== 'admin') {
            abort(403, 'Akses ditolak. Fitur Pengeluaran hanya dapat diakses oleh Admin.');
        }
    }

    /**
     * Tampilkan halaman pengelolaan pengeluaran.
     */
    public function index()
    {
        $this->checkAdminAccess();

        return view('pengeluaran.index', [
            'pageKey' => 'pengeluaran',
            'categories' => Expense::CATEGORIES,
        ]);
    }

    /**
     * Ambil data pengeluaran berupa JSON dengan filter pencarian dan rentang tanggal.
     *
     * Kolom tabel ms_pengeluaran:
     *   id_pengeluaran, id_user, tanggal, total, kategori, keterangan
     * Nama kunci JSON ke front-end tetap (expense_date, category, description, amount).
     */
    public function data(Request $request): JsonResponse
    {
        $this->checkAdminAccess();

        $query = Expense::query()->orderByDesc('tanggal')->orderByDesc('id_pengeluaran');

        if ($request->filled('from')) {
            $query->whereDate('tanggal', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('tanggal', '<=', $request->input('to'));
        }

        if ($request->filled('category')) {
            $query->where('kategori', $request->input('category'));
        }

        if ($request->filled('q')) {
            $search = '%' . trim((string) $request->input('q')) . '%';
            $query->where(function ($q) use ($search) {
                $q->where('kategori', 'like', $search)
                  ->orWhere('keterangan', 'like', $search);
            });
        }

        $expenses = $query->get();

        $totalAmount = (float) $expenses->sum('total');
        $totalCount  = $expenses->count();

        $data = $expenses->map(fn ($item) => $this->transform($item));

        return response()->json([
            'expenses'     => $data,
            'total_amount' => $totalAmount,
            'total_count'  => $totalCount,
        ]);
    }

    /**
     * Simpan pengeluaran baru.
     */
    public function store(Request $request): JsonResponse
    {
        $this->checkAdminAccess();

        $this->cleanAmountInput($request);

        $validated = $this->validateExpense($request);

        $expense = Expense::create($this->toColumns($validated, true));

        return response()->json([
            'message' => 'Pengeluaran berhasil dicatat.',
            'data'    => $this->transform($expense),
        ], 201);
    }

    /**
     * Perbarui data pengeluaran.
     */
    public function update(Request $request, Expense $expense): JsonResponse
    {
        $this->checkAdminAccess();

        $this->cleanAmountInput($request);

        $validated = $this->validateExpense($request);

        $expense->update($this->toColumns($validated, false));

        return response()->json([
            'message' => 'Pengeluaran berhasil diperbarui.',
            'data'    => $this->transform($expense->fresh()),
        ]);
    }

    /**
     * Hapus data pengeluaran.
     */
    public function destroy(Expense $expense): JsonResponse
    {
        $this->checkAdminAccess();

        $expense->delete();

        return response()->json([
            'message' => 'Pengeluaran berhasil dihapus.',
        ]);
    }

    /**
     * Validasi input form pengeluaran (nama field dari front-end).
     */
    private function validateExpense(Request $request): array
    {
        return $request->validate([
            'expense_date' => ['required', 'date'],
            'category'     => ['required', 'string', Rule::in(Expense::CATEGORIES)],
            'description'  => ['nullable', 'string', 'max:500'],
            'amount'       => ['required', 'numeric', 'gt:0'],
        ], [
            'expense_date.required' => 'Tanggal pengeluaran wajib diisi.',
            'expense_date.date'     => 'Format tanggal tidak valid.',
            'category.required'     => 'Kategori pengeluaran wajib dipilih.',
            'category.in'           => 'Kategori pengeluaran tidak valid.',
            'description.max'       => 'Keterangan tidak boleh lebih dari 500 karakter.',
            'amount.required'       => 'Jumlah pengeluaran wajib diisi.',
            'amount.numeric'        => 'Jumlah pengeluaran harus berupa angka numerik.',
            'amount.gt'             => 'Jumlah pengeluaran wajib lebih dari 0.',
        ]);
    }

    /**
     * Petakan field form ke nama kolom tabel ms_pengeluaran.
     */
    private function toColumns(array $validated, bool $isNew): array
    {
        $columns = [
            'tanggal'    => $validated['expense_date'],
            'kategori'   => $validated['category'],
            'keterangan' => $validated['description'] ?? null,
            'total'      => (int) round((float) $validated['amount']),
        ];

        if ($isNew) {
            $columns['id_user'] = Auth::id();
        }

        return $columns;
    }

    /**
     * Ubah satu baris pengeluaran ke format JSON yang dipakai front-end.
     */
    private function transform(Expense $item): array
    {
        return [
            'id'             => $item->id_pengeluaran,
            'expense_date'   => Carbon::parse($item->tanggal)->format('Y-m-d'),
            'formatted_date' => Carbon::parse($item->tanggal)->translatedFormat('d/m/Y'),
            'category'       => $item->kategori,
            'description'    => $item->keterangan ?? '-',
            'amount'         => (float) $item->total,
            'created_at'     => $item->created_at?->toIso8601String(),
        ];
    }

    /**
     * Bersihkan input amount bila dikirim dalam format mata uang atau teks dengan pemisah titik/koma.
     */
    private function cleanAmountInput(Request $request): void
    {
        if ($request->has('amount')) {
            $raw = $request->input('amount');
            if (is_string($raw)) {
                // Hapus string "Rp", spasi, dan titik ribuan
                $cleaned = preg_replace('/[^\d,.-]/', '', $raw);
                $cleaned = str_replace('.', '', $cleaned);
                $cleaned = str_replace(',', '.', $cleaned);
                $request->merge(['amount' => $cleaned]);
            }
        }
    }
}