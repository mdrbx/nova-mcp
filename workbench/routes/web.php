<?php

declare(strict_types=1);

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Mdrbx\NovaMcp\Tests\Fixtures\Record;
use Mdrbx\NovaMcp\Tests\Fixtures\User;

Route::get('/nova/login', function (): View {
    abort_unless(app()->environment('local'), 404);

    return view('demo-login');
})->name('nova.login');

Route::get('/login', fn () => redirect()->route('nova.login'))->name('login');

Route::post('/demo/sign-in', function (Request $request) {
    // This route exists only in the local workbench and never in the installed package.
    abort_unless(app()->environment('local'), 404);

    $user = User::query()->firstOrCreate(
        ['email' => 'alex@example.test'],
        ['name' => 'Alex Morgan', 'can_use_nova' => true],
    );

    Record::query()->firstOrCreate(['owner_id' => $user->id, 'name' => 'First demo record']);
    Auth::guard('web')->login($user);
    $request->session()->regenerate();

    return redirect()->intended(route('nova-mcp.connections.index'));
})->name('demo.signIn');
