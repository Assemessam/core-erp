<?php

use App\Modules\Organization\Application\Commands\AcceptInvitation;
use App\Modules\Organization\Domain\Invitations\InvitationRejected;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\DisposableConcurrencyDatabase;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
// Fail closed before opening a worker database session. Parent passes a fixed test-only target.
DisposableConcurrencyDatabase::requireSafeTarget(
    $app->environment('testing'), (string) config('database.connections.pgsql.database'),
    (string) getenv('COREERP_APPLICATION_DATABASE'),
);
if (config('database.default') !== 'pgsql' || config('database.connections.pgsql.url')) {
    throw new RuntimeException('Unsafe concurrency worker connection.');
}
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
DB::select("SELECT set_config('application_name', ?, false)", [$input['worker']]);
echo "ready\n";
flush();
try {
    $app->make(AcceptInvitation::class)->handle($input['user_id'], $input['email'], true, $input['invitation'], $input['token']);
    echo "accepted\n";
} catch (InvitationRejected $error) {
    echo 'rejected:'.$error->reason."\n";
}
