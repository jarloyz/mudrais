<?php

namespace Tests\Unit\Middleware;

use App\Http\Middleware\EnsureUserHasRole;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class EnsureUserHasRoleTest extends TestCase
{
    public function test_aborts_401_if_not_authenticated()
    {
        Auth::shouldReceive('check')->once()->andReturn(false);
        Log::shouldReceive('warning')->once();

        $request = Request::create('/test', 'GET');
        $middleware = new EnsureUserHasRole();

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Unauthorized');

        $middleware->handle($request, function () {}, 'admin');
    }

    public function test_aborts_403_if_missing_role()
    {
        Auth::shouldReceive('check')->once()->andReturn(true);
        Log::shouldReceive('warning')->once();

        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('hasAnyRole')->with(['admin'])->andReturn(false);
        $user->id = 1;

        $request = Request::create('/test', 'GET');
        $request->setUserResolver(function () use ($user) { return $user; });

        $middleware = new EnsureUserHasRole();

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Forbidden');

        $middleware->handle($request, function () {}, 'admin');
    }

    public function test_passes_if_has_role()
    {
        Auth::shouldReceive('check')->once()->andReturn(true);
        Log::shouldReceive('info')->once();

        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('hasAnyRole')->with(['admin', 'editor'])->andReturn(true);
        $user->id = 1;

        $request = Request::create('/test', 'GET');
        $request->setUserResolver(function () use ($user) { return $user; });

        $middleware = new EnsureUserHasRole();

        $called = false;
        $next = function ($req) use (&$called) {
            $called = true;
            return response('OK');
        };

        $response = $middleware->handle($request, $next, 'admin', 'editor');

        $this->assertTrue($called);
        $this->assertEquals('OK', $response->getContent());
    }
}
