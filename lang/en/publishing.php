<?php

/*
|--------------------------------------------------------------------------
| Publishing Gate Language Lines (English)
|--------------------------------------------------------------------------
|
| ClosedBetaPublishingGate's validation messages specifically — these were
| hardcoded English regardless of locale until 2026-08-11 (see
| SetLocaleFromHeaderMiddleware's docblock: that pass deliberately only
| covered the fixed API envelope, not every individual developer-authored
| exception message). Reproduced live: an Arabic-UI session saw this exact
| English text when a Facebook target had a media attachment.
|
*/

return [
    // 2026-08-11: FacebookOAuthProvider now genuinely publishes images and a
    // single video — this message now only fires for what's still actually
    // unsupported (see ClosedBetaPublishingGate::assertMediaSupportedByTargets).
    'facebook_media_not_supported' => 'This combination of media isn\'t supported for Facebook yet — Facebook allows one or more images, or exactly one video (not mixed with images, and not more than one video), per post.',
    'closed_beta_provider_restricted' => 'Only Facebook Pages and Telegram channels are enabled for the production closed beta.',
];
