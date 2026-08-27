<?php

use AdaiasMagdiel\Erlenmeyer\Request;
use AdaiasMagdiel\Erlenmeyer\Response;

$app->set404Handler(function (Request $req, Response $res, stdClass $params) {
    $res = $res->setStatusCode(404);
    $accept = $req->getHeader('Accept') ?? '';

    if (str_contains($accept, 'application/json')) {
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

    if (str_contains($accept, 'application/json')) {
        return $res->withJson([
            'status' => 'error',
            'message' => 'Internal Server Error'
        ]);
    }

    return $res->withTemplate(t('500'));
});
