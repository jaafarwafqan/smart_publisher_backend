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
    'facebook_media_not_supported' => 'Publishing media attachments to Facebook is not supported yet — remove the attachments or the Facebook target before publishing.',
    'closed_beta_provider_restricted' => 'Only Facebook Pages and Telegram channels are enabled for the production closed beta.',
];
