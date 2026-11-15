<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Cadangan: semua logika riwayat, pembayaran, dan pembatalan ada di OrderController.
 * Controller ini hanya meneruskan, supaya aturan stok dan pembayaran tidak ganda.
 * Saat ini route /riwayat memakai OrderController langsung (lihat routes/web.php).
 */
class RiwayatController extends Controller
{
    public function index()
    {
        return view('riwayat.index', ['pageKey' => 'riwayat']);
    }

    public function data()
    {
        return app(OrderController::class)->history();
    }

    public function pay(Request $request, $id)
    {
        return app(OrderController::class)->pay($request, $id);
    }

    public function cancel(Request $request, $id)
    {
        return app(OrderController::class)->cancel($request, $id);
    }

        public function update(Request $request, $id)
    {
        return app(OrderController::class)->update($request, $id);
    }
}