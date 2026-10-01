<?php

use App\Modules\Identity\Infrastructure\Eloquent\Models\User;
use App\Modules\Organization\Domain\Invitations\InvitationState;
use Illuminate\Support\Facades\DB;
use Tests\Support\OrganizationFixtures;

it('serializes two real PostgreSQL acceptance sessions and creates one membership', function () {
    $owner = User::factory()->create();
    $user = User::factory()->create();
    // Test invitation locking, with deletable raw setup. Invitation commands are not audited until D.
    $org = OrganizationFixtures::unaudited($owner->id, 'Concurrency test');
    $token = bin2hex(random_bytes(32));
    $invite = $org->invitations()->create(['email' => $user->email, 'inviter_user_id' => $owner->id, 'state' => InvitationState::Pending, 'expires_at' => now()->addDay(), 'token_hash' => hash('sha256', $token)]);
    $workers = [];
    $prefix = 'invitation-test-'.$invite->id;
    try {
        DB::beginTransaction();
        DB::table('organizations')->where('id', $org->id)->lockForUpdate()->first();
        for ($index = 0; $index < 2; $index++) {
            $process = proc_open([PHP_BINARY, base_path('tests/Support/accept-invitation-worker.php')], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, base_path());
            expect(is_resource($process))->toBeTrue();
            fwrite($pipes[0], json_encode(['worker' => $prefix.'-'.$index, 'user_id' => $user->id, 'email' => $user->email, 'invitation' => $invite->id, 'token' => $token], JSON_THROW_ON_ERROR));
            fclose($pipes[0]);
            stream_set_timeout($pipes[1], 10);
            $workers[] = [$process, $pipes];
            expect(trim(fgets($pipes[1])))->toBe('ready');
        }
        // Observe both independent sessions waiting on the same transaction before release.
        $waiting = 0;
        for ($attempt = 0; $attempt < 100; $attempt++) {
            DB::select('SELECT pg_stat_clear_snapshot()');
            $waiting = DB::table('pg_stat_activity')->where('application_name', 'like', $prefix.'%')->where('wait_event_type', 'Lock')->count();
            if ($waiting === 2) {
                break;
            }
            usleep(20000);
        }
        expect($waiting)->toBe(2);
        DB::commit();
        $outcomes = [];
        foreach ($workers as [$process, $pipes]) {
            $outcomes[] = trim(stream_get_contents($pipes[1]));
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            expect(proc_close($process))->toBe(0, $errors);
        }
        $workers = [];
        sort($outcomes);
        expect($outcomes)->toBe(['accepted', 'rejected:accepted']);
        expect($org->memberships()->where('user_id', $user->id)->count())->toBe(1);
        expect($invite->fresh()->state)->toBe(InvitationState::Accepted);
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        foreach ($workers as [$process, $pipes]) {
            if (is_resource($process)) {
                proc_terminate($process);
            }
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            if (is_resource($process)) {
                proc_close($process);
            }
        }
        $org->delete();
        $user->delete();
        $owner->delete();
    }
})->group('integration');
