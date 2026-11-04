<?php

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // Si la variable no está definida, cae a un dominio inválido (nunca a uno real de producción):
    // el navegador rechazará el CORS por completo, en vez de aceptar silenciosamente el origen equivocado.
    'allowed_origins' => ['https://'.env('SANCTUM_STATEFUL_DOMAINS', 'dominio-no-configurado.invalid')],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['Content-Disposition'],

    'max_age' => 0,

    'supports_credentials' => true,

];
