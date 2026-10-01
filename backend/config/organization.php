<?php

return [
    'invitation_days' => 7,
    // Credentials must never pass through the log/failover-to-log mailers.
    'invitation_mailer' => 'smtp',
];
