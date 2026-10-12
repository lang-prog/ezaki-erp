<?php

namespace Tests\Unit;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Tests\TestCase;

final class CsrfTokenContractTest extends TestCase
{
    public function test_laravel_accepts_the_decoded_encrypted_xsrf_header_for_the_session(): void
    {
        $token = str_repeat('a', 40);
        $encrypter = app('encrypter');
        $session = app('session')->driver();
        $session->put('_token', $token);
        $encryptedCookie = $encrypter->encrypt(
            CookieValuePrefix::create('XSRF-TOKEN', $encrypter->getKey()).$token,
            ValidateCsrfToken::serialized()
        );
        $request = Request::create('/', 'POST', [], [], [], ['HTTP_X_XSRF_TOKEN' => $encryptedCookie]);
        $request->setLaravelSession($session);
        $middleware = $this->csrfMiddleware();

        $response = $middleware->handle($request, fn () => response('accepted'));

        $this->assertSame('accepted', $response->getContent());
    }

    public function test_laravel_rejects_a_missing_or_still_url_encoded_xsrf_header(): void
    {
        $token = str_repeat('b', 40);
        $session = app('session')->driver();
        $session->put('_token', $token);
        $encrypter = app('encrypter');
        $encryptedCookie = $encrypter->encrypt(
            CookieValuePrefix::create('XSRF-TOKEN', $encrypter->getKey()).$token,
            ValidateCsrfToken::serialized()
        );

        foreach ([null, rawurlencode($encryptedCookie)] as $header) {
            $server = ['HTTP_ACCEPT' => 'application/json'];
            if ($header !== null) {
                $server['HTTP_X_XSRF_TOKEN'] = $header;
            }
            $request = Request::create('/api/v1/example', 'POST', [], [], [], $server);
            $request->setLaravelSession($session);

            try {
                $this->csrfMiddleware()->handle($request, fn () => response('must not pass'));
                $this->fail('The request must be rejected by CSRF middleware.');
            } catch (TokenMismatchException $exception) {
                $response = app(ExceptionHandler::class)->render($request, $exception);
                $this->assertSame(419, $response->getStatusCode());
            }
        }
    }

    private function csrfMiddleware(): ValidateCsrfToken
    {
        return new class(app(), app('encrypter')) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        };
    }
}
