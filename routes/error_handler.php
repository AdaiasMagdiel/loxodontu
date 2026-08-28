<?php

use AdaiasMagdiel\Erlenmeyer\Request;
use AdaiasMagdiel\Erlenmeyer\Response;

$app->set404Handler(function (Request $req, Response $res, stdClass $params) {
    $res = $res->setStatusCode(404);
    $accept = $req->getHeader('Accept') ?? '';

    if (expectsJson($req, $accept)) {
        return $res->withJson([
            'status' => 'error',
            'message' => 'Not Found'
        ]);
    }

    return $res->withTemplate(t('404'));
});

$app->setExceptionHandler(Throwable::class, function (Request $req, Response $res, Throwable $e) {
    if (isDev()) throw $e;

    $res = $res->setStatusCode(500);
    $accept = $req->getHeader('Accept') ?? '';

    if (expectsJson($req, $accept)) {
        return $res->withJson([
            'status' => 'error',
            'message' => 'Internal Server Error'
        ]);
    }

    return $res->withTemplate(t('500'));
});

if (!function_exists('expectsJson')) {
    function expectsJson(Request $req, string $accept): bool
    {
        return str_starts_with($req->getUri(), '/api/')
            || str_contains($accept, 'application/json');
    }
}
