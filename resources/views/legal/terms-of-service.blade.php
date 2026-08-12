@extends('legal.layout')

@section('title', 'Terms of Service')
@section('effectiveDate', '2026-08-12')

@section('content')
<p>
    Smart Publisher is operated by the <strong>University of Kufa — College of
    Nursing, Iraq</strong> and is currently available only to invited
    closed-beta testers. By using it, a tester agrees to use only
    organisations, Pages, channels, content, and credentials they are
    authorised to manage.
</p>

<h2>Scope of service</h2>
<p>
    The beta currently supports publishing to Telegram and Facebook Pages
    only. Any other integration shown in the product is marked
    <em>Coming soon</em> and must not be treated as an available publishing
    channel. Facebook Page access remains subject to Meta's own review and
    permission gates independent of this application.
</p>

<h2>Your responsibilities</h2>
<p>
    Testers remain responsible for the legality, accuracy, rights, and
    platform-policy compliance of content they publish through the service.
    Publishing can fail because a provider rejects a token, Page, channel,
    permission, rate, or piece of content — the system records the outcome
    and may retry an eligible failure, but does not guarantee delivery.
</p>
<p>
    Do not share access tokens, bot tokens, passwords, app signing material,
    or provider secrets with anyone. Report a suspected credential exposure to
    <a href="mailto:jaafarw.alkuby@uokufa.edu.iq">jaafarw.alkuby@uokufa.edu.iq</a>
    immediately so it can be rotated.
</p>

<h2>Changes and suspension</h2>
<p>
    The operator may suspend the beta or revoke a tester's access at any time
    to protect users, providers, or data. These terms may be updated as the
    service moves from closed beta toward general availability; material
    changes will be reflected on this page with an updated effective date.
</p>

<h2>Governing law</h2>
<p>
    These terms are governed by the laws of the Republic of Iraq, without
    regard to conflict-of-law principles.
</p>

<h2>Contact</h2>
<p>
    Questions about these terms:
    <a href="mailto:jaafarw.alkuby@uokufa.edu.iq">jaafarw.alkuby@uokufa.edu.iq</a>.
</p>
@endsection
