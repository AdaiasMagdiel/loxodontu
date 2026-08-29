<?php

use App\Edge\FunctionRequest;
use App\Edge\FunctionResponse;

require_once __DIR__ . '/FunctionRequest.php';
require_once __DIR__ . '/FunctionResponse.php';

$input = json_decode(stream_get_contents(STDIN), true);

if (!is_array($input)) {
    fwrite(STDERR, "Invalid sandbox input.\n");
    exit(1);
}

$codePath = (string) ($input['code_path'] ?? '');
if ($codePath === '' || !is_file($codePath)) {
    fwrite(STDERR, "Function source file not found.\n");
    exit(1);
}

$requestData = $input['request'] ?? [];
$request = new FunctionRequest(
    (int) ($requestData['project_id'] ?? 0),
    (string) ($requestData['method'] ?? 'POST'),
    is_array($requestData['headers'] ?? null) ? $requestData['headers'] : [],
    is_array($requestData['query'] ?? null) ? $requestData['query'] : [],
    is_array($requestData['body'] ?? null) ? $requestData['body'] : [],
    is_array($requestData['function'] ?? null) ? $requestData['function'] : [],
    is_array($requestData['auth'] ?? null) ? $requestData['auth'] : null,
);

ob_start();

try {
    $entrypoint = require $codePath;

    if (is_callable($entrypoint)) {
        $result = $entrypoint($request);
    } else {
        $result = $entrypoint;
    }

    $printed = ob_get_clean();

    if ($result instanceof FunctionResponse) {
        $payload = [
            'status' => $result->status,
            'headers' => $result->headers,
            'body' => $result->body,
        ];
    } else {
        $payload = [
            'status' => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => $result ?? ($printed !== '' ? $printed : null),
        ];
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    if (ob_get_level() > 0) {
        ob_end_clean();
    }

    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
