<?php

it('can reach the real PostgreSQL and Redis services', function () {
    // Read-only probe: never migrates, truncates, or flushes a developer's data.
    $this->getJson('/api/v1/ready')
        ->assertOk()
        ->assertExactJson(['data' => ['status' => 'ok']]);
})->group('integration');
