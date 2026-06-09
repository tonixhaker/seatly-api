<?php

declare(strict_types=1);

use App\Http\Exceptions\ErrorEnvelopeToResponseExtension;
use Dedoc\Scramble\Http\Middleware\RestrictedDocsAccess;
use Dedoc\Scramble\SecurityDocumentation\MiddlewareAuthSecurityStrategy;
use Dedoc\Scramble\Support\Generator\SecurityScheme;

return [
    'api_path' => 'api/v1*',

    'api_domain' => null,

    'export_path' => 'docs/openapi.json',

    'cache' => [
        'key' => 'scramble.openapi',
        'store' => 'file',
    ],

    'info' => [
        'version' => '1.0.0',
        'description' => 'The seatly-api REST surface. Generated from routes, Form Requests and API Resources.',
    ],

    'ui' => [
        'title' => 'Seatly API',
    ],

    'renderer' => 'elements',

    'enum_cases_description_strategy' => 'description',

    'enum_cases_names_strategy' => false,

    'flatten_deep_query_parameters' => true,

    'middleware' => [
        'web',
        RestrictedDocsAccess::class,
    ],

    'extensions' => [
        ErrorEnvelopeToResponseExtension::class,
    ],

    'security_strategy' => [
        MiddlewareAuthSecurityStrategy::class,
        ['scheme' => SecurityScheme::http('bearer')->as('bearerAuth')],
    ],
];
