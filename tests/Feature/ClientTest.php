<?php

namespace BiztechEG\Fawaterk\Tests\Feature;

use BiztechEG\Fawaterk\Accounts\AccountRepository;
use BiztechEG\Fawaterk\Auth\AccessTokenProvider;
use BiztechEG\Fawaterk\Contracts\FawaterkClient;
use BiztechEG\Fawaterk\Data\CartItem;
use BiztechEG\Fawaterk\Data\CreateTransaction;
use BiztechEG\Fawaterk\Data\Customer;
use BiztechEG\Fawaterk\Data\PaymentData\PaymentLink;
use BiztechEG\Fawaterk\Data\PaymentData\ReferenceCode;
use BiztechEG\Fawaterk\Exceptions\ApiException;
use BiztechEG\Fawaterk\Exceptions\AuthenticationException;
use BiztechEG\Fawaterk\Exceptions\ConfigurationException;
use BiztechEG\Fawaterk\Exceptions\InvalidRequestException;
use BiztechEG\Fawaterk\Exceptions\ServiceUnavailableException;
use BiztechEG\Fawaterk\Exceptions\TransactionNotFoundException;
use BiztechEG\Fawaterk\Exceptions\UnexpectedResponseException;
use BiztechEG\Fawaterk\Exceptions\ValidationException;
use BiztechEG\Fawaterk\Http\Client;
use BiztechEG\Fawaterk\Tests\TestCase;
use Closure;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

class ClientTest extends TestCase
{
    private const INTENT = '550e8400-e29b-41d4-a716-446655440000';

    /** @var list<Request> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->useCredentials();
    }

    public function test_create_transaction_sends_the_payload_with_a_bearer_token(): void
    {
        $this->api(fn () => Factory::response(['status' => 'success', 'data' => [
            'intent_key' => strtoupper(self::INTENT),
            'url' => 'https://staging.fawaterk.com/ts/a1b2c',
            'expires_in' => 2592000,
        ]]));

        $intent = $this->client()->createTransaction($this->request());

        [$token, $create] = $this->sent;

        $this->assertSame('https://staging.fawaterk.com/oauth/token', $token->url());
        $this->assertSame(['grant_type' => 'client_credentials', 'client_id' => 'test-client-id', 'client_secret' => 'test-client-secret'], $token->data());

        $this->assertSame('POST', $create->method());
        $this->assertSame('https://staging.fawaterk.com/api/v3/createTransaction', $create->url());
        $this->assertSame(['Bearer tok-1'], $create->header('Authorization'));
        $this->assertSame(['biztecheg/laravel-fawaterk'], $create->header('User-Agent'));
        $this->assertSame($this->request()->toPayload(), $create->data());

        $this->assertSame(self::INTENT, $intent->intentKey, 'intent keys are stored lowercased');
        $this->assertInstanceOf(PaymentLink::class, $intent->paymentData);
        $this->assertSame(2592000, $intent->expiresIn);
    }

    public function test_a_reference_code_answer_is_parsed(): void
    {
        $this->api(fn () => Factory::response(['status' => 'success', 'data' => [
            'intent_key' => self::INTENT,
            'payment_data' => ['referenceNumber' => '981335305', 'expireDate' => '2026-10-01 15:53:41'],
        ]]));

        $intent = $this->client()->createTransaction($this->request());

        $this->assertInstanceOf(ReferenceCode::class, $intent->paymentData);
        $this->assertSame('981335305', $intent->paymentData->referenceNumber);
    }

    public function test_the_token_is_fetched_once_and_reused(): void
    {
        $this->api(fn () => $this->paid());

        $this->client()->getTransaction(self::INTENT);
        $this->client()->getTransaction(self::INTENT);

        $this->assertSame(1, $this->requestsTo('/oauth/token'));
        $this->assertSame(2, $this->requestsTo('/api/v3/getTransactionData'));
    }

    public function test_a_401_gets_one_fresh_token_and_one_retry(): void
    {
        $answers = [Factory::response(['message' => 'Unauthenticated.'], 401), $this->paid()];
        $this->api(function () use (&$answers) {
            return array_shift($answers);
        });

        $this->assertTrue($this->client()->getTransaction(self::INTENT)->paid);
        $this->assertSame(2, $this->requestsTo('/oauth/token'));
        $this->assertSame(2, $this->requestsTo('/api/v3/getTransactionData'));
        $this->assertSame(['Bearer tok-2'], $this->sent[3]->header('Authorization'));
    }

    public function test_a_second_401_is_an_authentication_error_without_more_retries(): void
    {
        $this->api(fn () => Factory::response(['message' => 'Unauthenticated.'], 401));

        $this->assertRaises(fn () => $this->client()->getTransaction(self::INTENT), AuthenticationException::class);
        $this->assertSame(2, $this->requestsTo('/api/v3/getTransactionData'));
    }

    public function test_rejected_client_credentials_are_an_authentication_error(): void
    {
        $this->fakeFawaterkHttp(fn () => Factory::response(['error' => 'invalid_client'], 401));

        $this->assertRaises(fn () => $this->client()->getTransaction(self::INTENT), AuthenticationException::class);
    }

    public function test_a_422_carries_fawaterks_field_errors(): void
    {
        $this->api(fn () => Factory::response([
            'status' => 'error',
            'message' => ['customer.email' => ['The email is invalid.'], 'cartTotal' => 'Too small.'],
        ], 422));

        try {
            $this->client()->createTransaction($this->request());
            $this->fail('No exception.');
        } catch (ValidationException $e) {
            $this->assertSame(422, $e->status);
            $this->assertSame(['customer.email' => ['The email is invalid.'], 'cartTotal' => ['Too small.']], $e->errors);
            $this->assertSame('The email is invalid.; Too small.', $e->getMessage());
        }
    }

    public function test_the_short_intent_key_of_the_live_api_is_accepted_as_it_comes(): void
    {
        // The staging API answers with a short opaque key, not the UUID of the API reference.
        $this->api(fn (Request $request) => str_contains($request->url(), 'createTransaction')
            ? Factory::response(['status' => 'success', 'data' => [
                'intent_key' => 'kd7rwmxqoltbv3ezsa',
                'expires_in' => 7200,
                'url' => 'https://staging.fawaterk.com/ts/abcd2345?lg=ar',
            ]])
            : $this->paid('kd7rwmxqoltbv3ezsa'));

        $intent = $this->client()->createTransaction($this->request());

        $this->assertSame('kd7rwmxqoltbv3ezsa', $intent->intentKey);
        $this->assertInstanceOf(PaymentLink::class, $intent->paymentData);
        $this->assertSame(7200, $intent->expiresIn);

        $this->assertSame('kd7rwmxqoltbv3ezsa', $this->client()->getTransaction('kd7rwmxqoltbv3ezsa')->intentKey);
        $this->assertSame(['intent_key' => 'kd7rwmxqoltbv3ezsa'], end($this->sent)->data());
    }

    public function test_an_opaque_intent_key_keeps_its_case(): void
    {
        // Only a UUID is case-insensitive by definition; another key is sent back exactly as Fawaterk gave it.
        $this->api(fn () => $this->paid('AbCd1234EfGh'));

        $this->assertSame('AbCd1234EfGh', $this->client()->getTransaction('AbCd1234EfGh')->intentKey);
        $this->assertSame(['intent_key' => 'AbCd1234EfGh'], end($this->sent)->data());
    }

    public function test_a_value_that_cannot_be_an_intent_key_is_refused_before_any_request(): void
    {
        $this->api(fn () => $this->paid());

        foreach (['', 'short12', str_repeat('a', 37), 'has space1', 'kd7r/wmxqo', "kd7rwmxqol\n"] as $key) {
            $this->assertRaises(fn () => $this->client()->getTransaction($key), InvalidRequestException::class);
        }

        $this->assertSame([], $this->sent);
    }

    public function test_an_answer_with_a_malformed_intent_key_is_refused(): void
    {
        foreach (['short12', 'has space1', 123456789] as $key) {
            $this->api(fn () => Factory::response(['status' => 'success', 'data' => ['intent_key' => $key, 'url' => 'https://staging.fawaterk.com/ts/x']]));

            $this->assertRaises(fn () => $this->client()->createTransaction($this->request()), UnexpectedResponseException::class);
        }
    }

    public function test_an_unknown_intent_is_transaction_not_found(): void
    {
        $this->api(fn () => Factory::response(['status' => 'error', 'message' => 'Invalid intent_key or transaction not found'], 422));

        $this->assertRaises(fn () => $this->client()->getTransaction(self::INTENT), TransactionNotFoundException::class);
    }

    public function test_an_invalid_intent_key_is_refused_before_any_request(): void
    {
        $this->api(fn () => $this->paid());

        $this->assertRaises(fn () => $this->client()->getTransaction('../../oauth/token'), InvalidRequestException::class);
        $this->assertSame([], $this->sent);
    }

    public function test_server_errors_are_never_retried_on_create(): void
    {
        $status = 500;
        $this->api(function () use (&$status) {
            return Factory::response('<html>Bad gateway</html>', $status);
        });

        foreach ([500, 502, 503, 429] as $expected => $status) {
            $this->assertRaises(fn () => $this->client()->createTransaction($this->request()), ServiceUnavailableException::class);
            $this->assertSame($expected + 1, $this->requestsTo('/api/v3/createTransaction'), "HTTP {$status} must not create a second invoice.");
        }
    }

    public function test_redirects_are_not_followed(): void
    {
        $this->api(fn () => Factory::response('', 302, ['Location' => 'https://evil.test/steal']));

        $this->assertRaises(fn () => $this->client()->createTransaction($this->request()), UnexpectedResponseException::class);
        $this->assertSame(0, $this->requestsTo('evil.test'));
    }

    public function test_an_answer_about_another_intent_is_refused(): void
    {
        $this->api(fn () => $this->paid('6ba7b810-9dad-11d1-80b4-00c04fd430c8'));

        $this->assertRaises(fn () => $this->client()->getTransaction(self::INTENT), UnexpectedResponseException::class);
    }

    public function test_bodies_that_are_not_success_objects_are_refused(): void
    {
        $answer = Factory::response('not json', 200);
        $this->api(function () use (&$answer) {
            return $answer;
        });

        $this->assertRaises(fn () => $this->client()->getTransaction(self::INTENT), UnexpectedResponseException::class);

        $answer = Factory::response('["a", "list"]', 200);
        $this->assertRaises(fn () => $this->client()->getTransaction(self::INTENT), UnexpectedResponseException::class);

        $answer = Factory::response(['status' => 'error', 'message' => 'Payment method not active'], 200);
        $this->assertRaises(fn () => $this->client()->createTransaction($this->request()), ApiException::class, 'Payment method not active');
    }

    public function test_error_messages_are_truncated(): void
    {
        $this->api(fn () => Factory::response(['message' => str_repeat('x', 5000)], 400));

        try {
            $this->client()->createTransaction($this->request());
            $this->fail('No exception.');
        } catch (ApiException $e) {
            $this->assertSame(400, $e->status);
            $this->assertSame(300, mb_strlen($e->getMessage()));
        }
    }

    public function test_connection_failures_are_service_unavailable(): void
    {
        $this->fakeFawaterkHttp(function (Request $request) {
            throw new ConnectException('Connection refused', new PsrRequest($request->method(), $request->url()));
        });

        $this->assertRaises(fn () => $this->client()->getTransaction(self::INTENT), ServiceUnavailableException::class);
    }

    public function test_payment_methods_and_refund_pages_are_read(): void
    {
        $this->api(function (Request $request) {
            if (str_contains($request->url(), 'getTrPaymentmethods')) {
                return Factory::response(['status' => 'success', 'data' => [
                    ['payment_method_id' => 2, 'name_en' => 'Visa-Mastercard', 'name_ar' => 'فيزا', 'redirect' => 'true', 'commission_on_customer' => 2],
                    ['payment_method_id' => 3, 'name_en' => 'Fawry', 'name_ar' => 'فوري', 'redirect' => 'false', 'commission_on_customer' => 1],
                ]]);
            }

            return Factory::response(['current_page' => 2, 'last_page' => 3, 'data' => []]);
        });

        $methods = $this->client()->getPaymentMethods();
        $page = $this->client()->refundPage(2);

        $this->assertSame([2, 3], array_map(fn ($method) => $method->id, $methods));
        $this->assertSame('GET', $this->sent[1]->method());
        $this->assertSame('POST', $this->sent[2]->method());
        $this->assertSame('https://staging.fawaterk.com/api/v3/refund/index?page=2', $this->sent[2]->url());
        $this->assertSame('', $this->sent[2]->body(), 'refund/index takes no body');
        $this->assertTrue($page->hasMore());
    }

    public function test_transport_errors_never_escape_with_the_request_attached(): void
    {
        $failOn = null;
        $make = null;

        $this->api(fn () => $this->paid(), function (Request $request) use (&$failOn, &$make) {
            if ($failOn !== null && str_contains($request->url(), $failOn)) {
                // Guzzle's exception holds the real request: its body has the client secret, its headers the token.
                throw $make($request->toPsrRequest());
            }
        });

        $cases = [
            'TLS failure getting a token' => ['/oauth/token', fn ($psr) => new GuzzleRequestException('cURL error 60: SSL certificate problem', $psr, null, null, ['errno' => 60])],
            'connection reset on an API call' => ['/api/v3/', fn ($psr) => new GuzzleRequestException('cURL error 56: Recv failure', $psr, null, null, ['errno' => 56])],
            'transport error with a response' => ['/api/v3/', fn ($psr) => new GuzzleRequestException('cURL error 56: Recv failure', $psr, new PsrResponse(500), null, ['errno' => 56])],
            'generic transfer error' => ['/api/v3/', fn ($psr) => new TransferException('Something broke')],
        ];

        foreach ($cases as $name => [$failOn, $make]) {
            try {
                $this->client()->getTransaction(self::INTENT);
                $this->fail("No exception: {$name}");
            } catch (ServiceUnavailableException $e) {
                $this->assertNull($e->getPrevious(), "{$name}: the Guzzle exception must not be kept");
                $this->assertStringNotContainsString('test-client-secret', $e->getMessage());
                $this->assertStringNotContainsString('tok-', $e->getMessage());
            }
        }
    }

    public function test_a_create_that_may_have_been_processed_is_marked_outcome_unknown(): void
    {
        $answer = null;
        $this->api(function () use (&$answer) {
            return $answer;
        }, function (Request $request) use (&$answer) {
            if ($answer instanceof Closure && str_contains($request->url(), 'createTransaction')) {
                throw $answer($request->toPsrRequest());
            }
        });

        $cases = [
            'read timeout' => [fn ($psr) => new ConnectException('cURL error 28: Operation timed out', $psr, null, ['errno' => 28]), true],
            'empty reply' => [fn ($psr) => new ConnectException('cURL error 52: Empty reply', $psr, null, ['errno' => 52]), true],
            'no errno' => [fn ($psr) => new ConnectException('Stream error', $psr), true],
            'host not resolved' => [fn ($psr) => new ConnectException('cURL error 6: Could not resolve host', $psr, null, ['errno' => 6]), false],
            'connection refused' => [fn ($psr) => new ConnectException('cURL error 7: Failed to connect', $psr, null, ['errno' => 7]), false],
            'TLS handshake failed' => [fn ($psr) => new GuzzleRequestException('cURL error 60: SSL certificate problem', $psr, null, null, ['errno' => 60]), false],
            'HTTP 503' => [Factory::response('down', 503), true],
            'HTTP 429' => [Factory::response('slow down', 429), false],
        ];

        foreach ($cases as $name => [$response, $unknown]) {
            $answer = $response;
            $before = $this->requestsTo('createTransaction');

            try {
                $this->client()->createTransaction($this->request());
                $this->fail("No exception: {$name}");
            } catch (ServiceUnavailableException $e) {
                $this->assertSame($unknown, $e->outcomeUnknown, $name);
            }

            $this->assertSame($before + 1, $this->requestsTo('createTransaction'), "{$name}: sent exactly once");
        }
    }

    public function test_the_callers_timeout_also_bounds_the_token_request(): void
    {
        $timeouts = [];
        $this->api(fn () => $this->paid(), function (Request $request, array $options) use (&$timeouts) {
            $timeouts[] = $options['timeout'] ?? null;
        });

        $this->client()->getTransaction(self::INTENT, 8);
        $this->app->make(AccessTokenProvider::class)->forget($this->app->make(AccountRepository::class)->get());
        $this->client()->getTransaction(self::INTENT, 0);

        $this->assertSame([8, 8, 1, 1], $timeouts, 'the token request uses the same budget, and 0 never means "no timeout"');
    }

    public function test_the_package_http_client_fires_no_framework_http_events(): void
    {
        Event::fake([RequestSending::class]);

        // Control: the app's own client does fire the event this test looks for.
        Http::fake(['*' => Http::response('ok')]);
        Http::get('https://example.test');
        Event::assertDispatched(RequestSending::class);

        Event::fake([RequestSending::class]);
        $this->api(fn () => $this->paid());
        $this->client()->getTransaction(self::INTENT);

        Event::assertNotDispatched(RequestSending::class);
    }

    public function test_nothing_is_read_or_sent_until_the_client_is_used(): void
    {
        config()->set('fawaterk.client_secret', null);
        $this->fakeFawaterkHttp(fn () => $this->fail('No request should be sent.'));

        $client = $this->client();
        $this->assertInstanceOf(Client::class, $client);

        $this->assertRaises(fn () => $client->getTransaction(self::INTENT), ConfigurationException::class, 'FAWATERK_CLIENT_SECRET');
    }

    /**
     * Answer the token endpoint with a new token each time, and API calls
     * with the given handler. Every request is recorded in $this->sent.
     */
    private function api(Closure $handler, ?Closure $before = null): void
    {
        $tokens = 0;

        $this->fakeFawaterkHttp(function (Request $request, array $options) use ($handler, $before, &$tokens) {
            $this->sent[] = $request;

            if ($before !== null) {
                $before($request, $options);
            }

            if (str_ends_with($request->url(), '/oauth/token')) {
                $tokens++;

                return Factory::response(['token_type' => 'Bearer', 'expires_in' => 31536000, 'access_token' => "tok-{$tokens}"]);
            }

            return $handler($request);
        });
    }

    private function paid(string $intentKey = self::INTENT): mixed
    {
        return Factory::response(['status' => 'success', 'data' => [
            'intent_key' => $intentKey,
            'transaction_id' => 12345,
            'paid' => 1,
            'total' => 100,
            'currency' => 'EGP',
        ]]);
    }

    private function requestsTo(string $needle): int
    {
        return count(array_filter($this->sent, fn (Request $request) => str_contains($request->url(), $needle)));
    }

    private function client(): FawaterkClient
    {
        return $this->app->make(FawaterkClient::class);
    }

    private function request(): CreateTransaction
    {
        return new CreateTransaction(10000, new Customer('Ahmed', 'Ali', 'ahmed@example.com'), [new CartItem('Order #1', 10000)]);
    }
}
