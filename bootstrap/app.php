<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \App\Http\Middleware\CheckRole::class,
        ]);

        // Belum login -> ke halaman login
        $middleware->redirectGuestsTo(fn (Request $request) => route('login'));

        // Sudah login tapi buka /login -> ke halaman utama sesuai role
        $middleware->redirectUsersTo(function (Request $request) {
            return match ($request->user()?->role) {
                'kasir'               => route('kasir.index'),
                'superadmin', 'admin' => route('dashboard'),
                default               => route('login'),
            };
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();