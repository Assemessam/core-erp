<h1>Join {{ $organizationName }}</h1>
<p>You have been invited to join this organization. Sign in or register with this email address, verify your email, then accept.</p>
<p><a href="{{ $invitationUrl }}">Accept invitation</a></p>
<p>This invitation expires in {{ config('organization.invitation_days') }} days. If you did not expect it, you can ignore this email.</p>
