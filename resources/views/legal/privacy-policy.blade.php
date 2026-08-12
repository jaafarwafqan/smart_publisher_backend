@extends('legal.layout')

@section('title', 'Privacy Policy')
@section('effectiveDate', '2026-08-12')

@section('content')
<p>
    Smart Publisher is a closed-beta workspace for drafting, scheduling, and
    publishing content to Telegram and Facebook Pages. This document describes
    the product as implemented for the beta.
</p>

<h2>Who operates this service</h2>
<p>
    Smart Publisher is operated by the <strong>University of Kufa — College of
    Nursing, Iraq</strong>. For any privacy question, request, or concern,
    contact <a href="mailto:jaafarw.alkuby@uokufa.edu.iq">jaafarw.alkuby@uokufa.edu.iq</a>.
</p>

<h2>Data we process</h2>
<p>
    The service processes account profile data needed to sign in, organisation
    and membership data, drafts and published-post metadata, uploaded media,
    audit and operational logs, notification records, and the identifiers and
    credentials needed to connect an approved social account. The application
    receives only the provider permissions granted during the connection flow
    (for Facebook: <code>pages_show_list</code>, <code>pages_read_engagement</code>,
    <code>pages_manage_posts</code>).
</p>
<p>
    Access and refresh tokens for connected social accounts are encrypted at
    rest. They are used only to provide the requested connection, Page/channel
    discovery, and publishing functions — never stored in client-side source
    code, logs, test fixtures, or documentation.
</p>

<h2>How data is used and shared</h2>
<p>
    Data is used to authenticate users, enforce organisation membership,
    deliver publishing requests, diagnose failures, and operate the service. A
    publish request necessarily sends the selected content and target
    identifier to the selected provider (Telegram or Facebook). The service
    does not enable unimplemented providers in production or present a mock
    integration as a real publish.
</p>

<h2>Retention</h2>
<p>
    Operational data (posts, media, logs, connection records) is retained for
    as long as the account remains active. Following a verified account
    deletion request (see <a href="/legal/data-deletion">Data Deletion</a>),
    account data is retained for up to <strong>30 days</strong> to allow the
    request to be reversed in case of error, then permanently deleted or
    anonymised — except where a shorter or longer period is required by
    applicable law (e.g. financial or audit records).
</p>
<p>
    Production traffic is TLS-only. Credentials are supplied through a secret
    store, never committed configuration.
</p>

<h2>Your rights</h2>
<p>
    An authenticated user can request deletion of their account data at any
    time — see <a href="/legal/data-deletion">Data Deletion</a> for how. You
    may also contact
    <a href="mailto:jaafarw.alkuby@uokufa.edu.iq">jaafarw.alkuby@uokufa.edu.iq</a>
    directly with any access, correction, or deletion request.
</p>
@endsection
