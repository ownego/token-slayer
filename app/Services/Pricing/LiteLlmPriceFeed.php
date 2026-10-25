<?php

namespace App\Services\Pricing;

use App\Exceptions\ModelPriceFeedException;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use JsonException;
use Psr\Http\Message\ResponseInterface;

/**
 * Fetches the LiteLLM price file. The feed is an outside source, so the
 * fetch only ever accepts plain JSON data from one fixed URL, of bounded
 * size and depth; everything else is refused before parsing.
 */
class LiteLlmPriceFeed
{
    /**
     * Fetch the feed, conditionally on the ETag of the last applied file.
     *
     * The URL comes from config only. Redirects are not followed, so the
     * request never leaves that host. A declared or streamed size over
     * `max_bytes` aborts the download early; the length check on the body is
     * the guard that always runs.
     *
     * @param  ?string  $etag  the ETag stored from the last applied fetch
     * @return PriceFeedResponse
     *
     * @throws ModelPriceFeedException
     */
    public function fetch(?string $etag): PriceFeedResponse
    {
        $maxBytes = (int) config('token_slayer.model_prices.max_bytes');
        $headers = $etag !== null ? ['If-None-Match' => $etag] : [];

        try {
            $response = Http::timeout(config('token_slayer.model_prices.timeout_seconds'))
                ->connectTimeout(config('token_slayer.model_prices.connect_timeout_seconds'))
                ->withHeaders($headers)
                ->withOptions([
                    'allow_redirects' => false,
                    'on_headers' => function (ResponseInterface $response) use ($maxBytes): void {
                        $declaredBytes = (int) $response->getHeaderLine('Content-Length');

                        if ($declaredBytes > $maxBytes) {
                            throw new ModelPriceFeedException(ModelPriceFeedException::TOO_LARGE);
                        }
                    },
                    'progress' => function (int $expectedBytes, int $downloadedBytes) use ($maxBytes): void {
                        if ($downloadedBytes > $maxBytes) {
                            throw new ModelPriceFeedException(ModelPriceFeedException::TOO_LARGE);
                        }
                    },
                ])
                ->get(config('token_slayer.model_prices.feed_url'));
        } catch (ConnectionException|RequestException|TransferException $exception) {
            throw $this->sizeRefusalWithin($exception)
                ?? new ModelPriceFeedException(ModelPriceFeedException::UNREACHABLE, $exception);
        }

        if ($response->status() === 304) {
            return new PriceFeedResponse(true, [], null, null);
        }

        if ($response->status() !== 200) {
            throw new ModelPriceFeedException(ModelPriceFeedException::UNEXPECTED_STATUS);
        }

        $body = $response->body();

        if (strlen($body) > $maxBytes) {
            throw new ModelPriceFeedException(ModelPriceFeedException::TOO_LARGE);
        }

        $entries = $this->decodeObject($body);
        $responseEtag = $response->header('ETag') ?: null;
        $sha256 = hash('sha256', $body);

        return new PriceFeedResponse(false, $entries, $responseEtag, $sha256);
    }

    /**
     * The size refusal our own download callbacks threw, if the HTTP client
     * wrapped it in its transfer exception.
     *
     * @param  \Throwable  $exception  the exception the HTTP call raised
     * @return ?ModelPriceFeedException
     */
    private function sizeRefusalWithin(\Throwable $exception): ?ModelPriceFeedException
    {
        for ($cause = $exception->getPrevious(); $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof ModelPriceFeedException) {
                return $cause;
            }
        }

        return null;
    }

    /**
     * Decode the body, accepting only a non-empty JSON object within the depth cap
     * (a list or an empty object is not a price file).
     *
     * @param  string  $body  the raw response body
     * @return array<array-key, mixed>
     *
     * @throws ModelPriceFeedException
     */
    private function decodeObject(string $body): array
    {
        $maxDepth = (int) config('token_slayer.model_prices.json_max_depth');

        try {
            $decoded = json_decode($body, true, $maxDepth, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ModelPriceFeedException(ModelPriceFeedException::MALFORMED, $exception);
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new ModelPriceFeedException(ModelPriceFeedException::MALFORMED);
        }

        return $decoded;
    }
}
