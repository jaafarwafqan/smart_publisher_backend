<?php

/*
|--------------------------------------------------------------------------
| API Envelope Language Lines (English)
|--------------------------------------------------------------------------
|
| The small, fixed set of generic messages bootstrap/app.php's exception
| renderer emits for every API request regardless of which controller/
| exception raised it (validation failure, unauthenticated, not found,
| unauthorized, generic 4xx/5xx). Deliberately does NOT cover every
| individual developer-authored exception message ($e->getMessage()) used
| across the ~150 controller call sites — that is out of scope for this
| root-cause fix; those stay in whatever language the throwing code wrote
| them in.
|
*/

return [
    'validation_failed' => 'Validation failed',
    'unauthenticated' => 'Unauthenticated.',
    'internal_server_error' => 'Internal server error',
    'resource_not_found' => 'Resource not found.',
    'unauthorized_action' => 'This action is unauthorized.',
    'request_failed' => 'Request failed',
];
