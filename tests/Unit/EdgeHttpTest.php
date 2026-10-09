<?php

namespace App\Net {
    class EdgeHttpCurlMock
    {
        public static mixed $initResult = 'curl-handle';
        public static string|false $execResult = '{"ok":true}';
        public static string $error = '';
        public static int $status = 200;

        /** @var array<string, mixed> */
        public static array $options = [];

        /** @var array<int, array{0: int, 1: string}> Redirects ([status, location]) served before the final response. */
        public static array $redirects = [];

        /** @var string[] Every URL passed to curl_init(), in order. */
        public static array $requested = [];

        public static function reset(): void
        {
            self::$initResult = 'curl-handle';
            self::$execResult = '{"ok":true}';
            self::$error = '';
            self::$status = 200;
            self::$options = [];
            self::$redirects = [];
            self::$requested = [];
        }
    }

    function curl_init(string $url): mixed
    {
        EdgeHttpCurlMock::$options = ['url' => $url];
        EdgeHttpCurlMock::$requested[] = $url;

        return EdgeHttpCurlMock::$initResult;
    }

    /**
     * @param array<int, mixed> $options
     */
    function curl_setopt_array(mixed $handle, array $options): bool
    {
        EdgeHttpCurlMock::$options += $options;

        return true;
    }

    function curl_setopt(mixed $handle, int $option, mixed $value): bool
    {
        EdgeHttpCurlMock::$options[$option] = $value;

        return true;
    }

    function curl_exec(mixed $handle): bool
    {
        if (EdgeHttpCurlMock::$redirects !== []) {
            [$status, $location] = array_shift(EdgeHttpCurlMock::$redirects);
            EdgeHttpCurlMock::$status = $status;
            (EdgeHttpCurlMock::$options[\CURLOPT_HEADERFUNCTION])($handle, "Location: {$location}\r\n");

            return true;
        }
        EdgeHttpCurlMock::$status = EdgeHttpCurlMock::$status >= 300 && EdgeHttpCurlMock::$status < 400 ? 200 : EdgeHttpCurlMock::$status;

        if (isset(EdgeHttpCurlMock::$options[\CURLOPT_HEADERFUNCTION])) {
            $header = EdgeHttpCurlMock::$options[\CURLOPT_HEADERFUNCTION];
            $header($handle, "HTTP/1.1 " . EdgeHttpCurlMock::$status . " Test\r\n");
            $header($handle, "X-Test: yes\r\n");
        }

        if (EdgeHttpCurlMock::$execResult !== false && isset(EdgeHttpCurlMock::$options[\CURLOPT_WRITEFUNCTION])) {
            (EdgeHttpCurlMock::$options[\CURLOPT_WRITEFUNCTION])($handle, EdgeHttpCurlMock::$execResult);
        }

        return EdgeHttpCurlMock::$execResult === false ? false : true;
    }

    function curl_error(mixed $handle): string
    {
        return EdgeHttpCurlMock::$error;
    }

    function curl_getinfo(mixed $handle, int $option): int
    {
        return EdgeHttpCurlMock::$status;
    }

    function curl_close(mixed $handle): void
    {
        EdgeHttpCurlMock::$options['closed'] = true;
    }
}

namespace {
    use App\Net\EdgeHttpCurlMock;
    use App\Edge\Http;

    beforeEach(function () {
        EdgeHttpCurlMock::reset();
    });

    test('edge http helper rejects filesystem urls', function () {
        expect(fn () => Http::get('file:///etc/passwd'))
            ->toThrow(RuntimeException::class, 'Only http and https URLs are allowed.');
    });

    test('edge http helper rejects private network targets', function () {
        expect(fn () => Http::post('http://127.0.0.1', ['ok' => true]))
            ->toThrow(RuntimeException::class, 'Private and reserved network targets are not allowed.');
    });

    test('edge http helper rejects localhost targets', function () {
        expect(fn () => Http::get('http://localhost'))
            ->toThrow(RuntimeException::class, 'Localhost requests are not allowed.');
    });

    test('edge http helper accepts public http and https urls', function () {
        expect(App\Net\UrlGuard::assertAllowed('http://93.184.216.34')['port'])->toBe(80);
        expect(App\Net\UrlGuard::assertAllowed('https://93.184.216.34')['port'])->toBe(443);
    });

    test('edge http helper rejects hosts resolving only to private ipv6 addresses', function () {
        expect(fn () => Http::get('http://[fc00::1]'))
            ->toThrow(RuntimeException::class, 'Private and reserved network targets are not allowed.');
    });

    test('edge http helper rejects ipv6 link-local targets', function () {
        expect(fn () => Http::get('http://[fe80::1]'))
            ->toThrow(RuntimeException::class, 'Private and reserved network targets are not allowed.');
    });

    test('edge http helper follows redirects, re-validating every hop', function () {
        EdgeHttpCurlMock::$redirects = [[302, '/next'], [301, 'https://93.184.216.35/final']];

        $response = Http::get('https://93.184.216.34/start', ['Authorization' => 'Bearer secret']);

        expect($response['status'])->toBe(200);
        expect(EdgeHttpCurlMock::$requested)->toBe([
            'https://93.184.216.34/start',
            'https://93.184.216.34/next',
            'https://93.184.216.35/final',
        ]);
        // Credentials never travel to a different host.
        expect(EdgeHttpCurlMock::$options[\CURLOPT_HTTPHEADER])->not->toContain('Authorization: Bearer secret');
    });

    test('edge http helper refuses a redirect to a private address', function () {
        EdgeHttpCurlMock::$redirects = [[302, 'http://169.254.169.254/latest/meta-data/']];

        expect(fn () => Http::get('https://93.184.216.34/start'))
            ->toThrow(RuntimeException::class, 'Private and reserved network targets are not allowed.');
        expect(EdgeHttpCurlMock::$requested)->toBe(['https://93.184.216.34/start']);
    });

    test('a 303 redirect turns a post into a get without the body', function () {
        EdgeHttpCurlMock::$redirects = [[303, '/done']];

        Http::post('https://93.184.216.34/submit', ['a' => 1]);

        expect(EdgeHttpCurlMock::$options[\CURLOPT_CUSTOMREQUEST])->toBe('GET');
        expect(EdgeHttpCurlMock::$options)->not->toHaveKey(\CURLOPT_POSTFIELDS);
    });

    test('edge http helper performs get requests through curl', function () {
        EdgeHttpCurlMock::$status = 201;

        $response = Http::get('https://93.184.216.34', ['Accept' => 'application/json'], 30);

        expect($response)->toMatchArray([
            'ok' => true,
            'status' => 201,
            'headers' => ['x-test' => 'yes'],
            'body' => '{"ok":true}',
            'error' => null,
        ]);
        expect(EdgeHttpCurlMock::$options['url'])->toBe('https://93.184.216.34');
        expect(EdgeHttpCurlMock::$options[\CURLOPT_CUSTOMREQUEST])->toBe('GET');
        expect(EdgeHttpCurlMock::$options[\CURLOPT_CONNECTTIMEOUT])->toBe(10);
        expect(EdgeHttpCurlMock::$options['closed'])->toBeTrue();
    });

    test('edge http helper serializes array post bodies as json', function () {
        Http::post('https://93.184.216.34', ['message' => 'hello']);

        expect(EdgeHttpCurlMock::$options[\CURLOPT_CUSTOMREQUEST])->toBe('POST');
        expect(EdgeHttpCurlMock::$options[\CURLOPT_HTTPHEADER])->toContain('Content-Type: application/json');
        expect(EdgeHttpCurlMock::$options[\CURLOPT_POSTFIELDS])->toBe('{"message":"hello"}');
    });

    test('edge http helper returns curl errors without throwing', function () {
        EdgeHttpCurlMock::$status = 0;
        EdgeHttpCurlMock::$execResult = false;
        EdgeHttpCurlMock::$error = 'Could not resolve host';

        $response = Http::request('DELETE', 'https://93.184.216.34', null, [], 0);

        expect($response)->toMatchArray([
            'ok' => false,
            'status' => 0,
            'body' => '',
            'error' => 'Could not resolve host',
        ]);
        expect(EdgeHttpCurlMock::$options[\CURLOPT_CONNECTTIMEOUT])->toBe(1);
    });

    test('edge http helper reports failed curl initialization', function () {
        EdgeHttpCurlMock::$initResult = false;

        expect(fn () => Http::get('https://93.184.216.34'))
            ->toThrow(RuntimeException::class, 'Unable to initialize HTTP request.');
    });
}
