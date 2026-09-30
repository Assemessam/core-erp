<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

it('serves a versioned JSON liveness response without dependencies', function () {
    DB::shouldReceive('select')->never();
    Redis::shouldReceive('connection')->never();

    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertExactJson(['data' => ['status' => 'ok']]);
});

it('is ready when both dependencies respond', function () {
    DB::shouldReceive('select')->once()->with('SELECT 1')->andReturn([]);
    Redis::shouldReceive('connection->ping')->once()->andReturn(true);

    $this->getJson('/api/v1/ready')
        ->assertOk()
        ->assertExactJson(['data' => ['status' => 'ok']]);
});

it('returns a safe unavailable response when PostgreSQL fails', function () {
    DB::shouldReceive('select')->once()->andThrow(new RuntimeException('private database details'));
    Redis::shouldReceive('connection')->never();

    $this->getJson('/api/v1/ready')
        ->assertStatus(503)
        ->assertExactJson(['data' => ['status' => 'unavailable']]);
});

it('returns unavailable when Redis fails', function () {
    DB::shouldReceive('select')->once()->andReturn([]);
    Redis::shouldReceive('connection->ping')->once()->andThrow(new RuntimeException('private redis details'));

    $this->getJson('/api/v1/ready')
        ->assertStatus(503)
        ->assertExactJson(['data' => ['status' => 'unavailable']]);
});

it('returns unavailable when Redis does not acknowledge the ping', function () {
    DB::shouldReceive('select')->once()->andReturn([]);
    Redis::shouldReceive('connection->ping')->once()->andReturn(false);

    $this->getJson('/api/v1/ready')->assertStatus(503);
});

it('renders unknown API routes as JSON even without an Accept header', function () {
    $this->get('/api/v1/does-not-exist')
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/json');
});
