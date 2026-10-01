<?php

use App\Modules\Organization\Application\Commands\AcceptInvitation;
use App\Modules\Organization\Domain\Invitations\InvitationRejected;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
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
