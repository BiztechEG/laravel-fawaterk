<?php

namespace BiztechEG\Fawaterk\Http;

use BiztechEG\Fawaterk\Accounts\Credentials;
use BiztechEG\Fawaterk\Auth\AccessTokenProvider;
use BiztechEG\Fawaterk\Contracts\FawaterkClient;
use BiztechEG\Fawaterk\Data\CreateTransaction;
use BiztechEG\Fawaterk\Data\IntentKey;
use BiztechEG\Fawaterk\Data\PaymentData\PaymentDataParser;
use BiztechEG\Fawaterk\Data\PaymentMethod;
use BiztechEG\Fawaterk\Data\RefundPage;
use BiztechEG\Fawaterk\Data\TransactionData;
use BiztechEG\Fawaterk\Data\TransactionIntent;
use BiztechEG\Fawaterk\Exceptions\ApiException;
use BiztechEG\Fawaterk\Exceptions\AuthenticationException;
use BiztechEG\Fawaterk\Exceptions\InvalidRequestException;
use BiztechEG\Fawaterk\Exceptions\ServiceUnavailableException;
use BiztechEG\Fawaterk\Exceptions\TransactionNotFoundException;
use BiztechEG\Fawaterk\Exceptions\UnexpectedResponseException;
use BiztechEG\Fawaterk\Exceptions\ValidationException;
use BiztechEG\Fawaterk\Support\Json;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\HttpClientException;

/**
 * The real API v3 client.
 *
 * - It uses its own HTTP factory with no event dispatcher, so framework HTTP
 *   events (and tools that record them, such as Telescope) never see the
 *   client secret or the access token.
 * - Redirects are never followed.
 * - Nothing is retried, except one request after a 401 with a fresh token
 *   (a 401 means the request was not processed).
 * - Exceptions carry the status and Fawaterk's short message, never a body,
 *   and never the underlying HTTP exception (it holds the request).
 */
final class Client implements FawaterkClient
{
    private const MESSAGE_LIMIT = 300;

    public function __construct(
        private readonly Factory $http,
        private readonly AccessTokenProvider $tokens,
        private readonly Credentials $credentials,
        private readonly PaymentDataParser $parser,
    ) {}

    public function createTransaction(CreateTransaction $request): TransactionIntent
    {
        $json = $this->send('POST', '/api/v3/createTransaction', $request->toPayload());

        return TransactionIntent::fromResponse($json, $this->parser);
    }

    public function getTransaction(string $intentKey, ?int $timeout = null): TransactionData
    {
        $key = IntentKey::normalize($intentKey);

        if ($key === null) {
            throw new InvalidRequestException('That is not an intent key.');
        }

        try {
            $json = $this->send('POST', '/api/v3/getTransactionData', ['intent_key' => $key], [], $timeout);
        } catch (ValidationException $e) {
            throw new TransactionNotFoundException($e->getMessage(), 422, $e->errors);
        }

        $data = TransactionData::fromResponse($json);

        if ($data->intentKey !== $key) {
            throw new UnexpectedResponseException('Fawaterk returned a different transaction than the one asked for.');
        }

        return $data;
    }

    public function getPaymentMethods(): array
    {
        $json = $this->send('GET', '/api/v3/getTrPaymentmethods');
        $data = $json['data'] ?? null;

        if (! is_array($data)) {
            throw new UnexpectedResponseException('Fawaterk returned no payment methods list.');
        }

        return array_values(array_map(
            fn ($item) => PaymentMethod::fromArray(is_array($item) ? $item : []),
            $data,
        ));
    }

    public function refundPage(int $page = 1): RefundPage
    {
        $json = $this->send('POST', '/api/v3/refund/index', [], ['page' => max(1, $page)], null, true);

        return RefundPage::fromResponse($json);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function send(
        string $method,
        string $path,
        array $body = [],
        array $query = [],
        ?int $timeout = null,
        bool $paginated = false,
        bool $isRetry = false,
    ): array {
        $options = [];

        if ($query !== []) {
            $options['query'] = $query;
        }

        if ($method !== 'GET' && $body !== []) {
            $options['json'] = $body;
        }

        // 0 would mean "no timeout" to Guzzle.
        $timeout = max(1, $timeout ?? $this->credentials->timeout);
        $token = $this->tokens->token($this->credentials, $timeout);

        try {
            $response = $this->http
                ->withoutRedirecting()
                ->acceptJson()
                ->withToken($token)
                ->withHeaders(['User-Agent' => 'biztecheg/laravel-fawaterk'])
                ->timeout($timeout)
                ->connectTimeout(min($this->credentials->connectTimeout, $timeout))
                ->send($method, $this->credentials->url($path), $options);
        } catch (HttpClientException|TransferException $e) {
            throw TransportFailure::from($e, 'Fawaterk could not be reached.');
        }

        $status = $response->status();
        $json = Json::decodeObject($response->body());

        if ($status === 401) {
            if (! $isRetry) {
                $this->tokens->forget($this->credentials);

                return $this->send($method, $path, $body, $query, $timeout, $paginated, true);
            }

            throw new AuthenticationException(self::message($json) ?? 'Fawaterk rejected the access token.', 401);
        }

        if ($status === 422) {
            throw new ValidationException(self::message($json) ?? 'Fawaterk rejected the request.', 422, self::errors($json));
        }

        if ($status >= 500 || $status === 429) {
            throw new ServiceUnavailableException(
                self::message($json) ?? "Fawaterk is unavailable (HTTP {$status}).",
                $status,
                outcomeUnknown: $status !== 429,
            );
        }

        if ($status >= 300 && $status < 400) {
            throw new UnexpectedResponseException("Fawaterk answered with a redirect (HTTP {$status}); it was not followed.");
        }

        if ($status >= 400) {
            throw new ApiException(self::message($json) ?? "Fawaterk answered with HTTP {$status}.", $status);
        }

        if ($json === null) {
            throw new UnexpectedResponseException('Fawaterk returned a body that is not a JSON object.');
        }

        // Paginated lists (refund/index) have no "status" field.
        if (! $paginated && ($json['status'] ?? null) !== 'success') {
            throw new ApiException(self::message($json) ?? 'Fawaterk reported a failure.', $status);
        }

        return $json;
    }

    /**
     * Fawaterk's `message` is a string, or an object of field => messages.
     *
     * @param  array<string, mixed>|null  $json
     */
    private static function message(?array $json): ?string
    {
        $message = $json['message'] ?? null;

        if (is_array($message)) {
            $message = implode('; ', array_merge(...array_values(self::errors($json))));
        }

        if (! is_string($message) || trim($message) === '') {
            return null;
        }

        return mb_substr(trim($message), 0, self::MESSAGE_LIMIT);
    }

    /**
     * @param  array<string, mixed>|null  $json
     * @return array<string, list<string>>
     */
    private static function errors(?array $json): array
    {
        $source = is_array($json['errors'] ?? null) ? $json['errors'] : ($json['message'] ?? null);

        if (! is_array($source)) {
            return [];
        }

        $errors = [];
        foreach ($source as $field => $messages) {
            $list = array_values(array_filter(
                is_array($messages) ? $messages : [$messages],
                fn ($message) => is_string($message) && $message !== '',
            ));

            if ($list !== []) {
                $errors[(string) $field] = array_map(fn ($m) => mb_substr($m, 0, self::MESSAGE_LIMIT), $list);
            }
        }

        return $errors;
    }
}
