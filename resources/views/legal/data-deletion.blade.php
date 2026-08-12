@extends('legal.layout')

@section('title', 'Data Deletion Instructions')
@section('effectiveDate', '2026-08-12')

@section('content')
<p>
    You can request deletion of your Smart Publisher account and its
    associated data at any time, using either of the two methods below.
</p>

<h2>Option 1 — In the app (recommended)</h2>
<ol>
    <li>Open Smart Publisher and sign in.</li>
    <li>Go to <strong>Settings</strong>.</li>
    <li>Tap <strong>Delete my account</strong>.</li>
    <li>Confirm the request.</li>
</ol>
<p>
    This creates a durable, auditable deletion request tied to your account
    and returns a request ID for your records. Technically, the app calls:
</p>
<pre>POST /api/v1/account/data-deletion-requests
Authorization: Bearer &lt;your session token&gt;
Content-Type: application/json

{
  "confirm": true,
  "reason": "Optional explanation"
}</pre>

<h2>Option 2 — By email</h2>
<p>
    If you can no longer access the app (lost credentials, device lost, etc.),
    email <a href="mailto:jaafarw.alkuby@uokufa.edu.iq">jaafarw.alkuby@uokufa.edu.iq</a>
    from the address associated with your account and request deletion. We
    will verify your identity before processing the request.
</p>

<h2>What happens next</h2>
<p>
    Once a deletion request is verified, we revoke any connected social
    account tokens (Facebook, Telegram) associated with your account, then
    delete or anonymise your account data. Data is retained for up to
    <strong>30 days</strong> after a verified request to allow it to be
    reversed in case of error, then permanently removed — except where a
    longer retention period is required by law (e.g. audit or financial
    records).
</p>
<p>
    Removing or unlinking the Smart Publisher Facebook integration from your
    Facebook settings does not by itself delete your Smart Publisher account
    data — please also submit a request through one of the two options above.
</p>
@endsection
