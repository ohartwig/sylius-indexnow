<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Sylius\Http;

use Koh\IndexNow\HostPolicy;
use Koh\IndexNow\IndexNowClient;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * PSR-18 over the application's http_client, with the limits a cron job needs.
 *
 * The application's own HTTP settings (proxy, CA bundle) stay in force; this
 * adds a ten-second timeout, a hard ceiling for the whole exchange and no
 * redirects — the request body carries the key.
 */
final readonly class TimeLimitedHttpClient implements ClientInterface
{
    public const TIMEOUT = 10;
    public const MAX_DURATION = 20;

    private Psr18Client $client;

    public function __construct(HttpClientInterface $http, ResponseFactoryInterface $responses, StreamFactoryInterface $streams)
    {
        $this->client = new Psr18Client(
            $http->withOptions(['timeout' => self::TIMEOUT, 'max_duration' => self::MAX_DURATION, 'max_redirects' => 0]),
            $responses,
            $streams,
        );
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return $this->client->sendRequest($request);
    }

    /** @return \Closure(HostPolicy): IndexNowClient */
    public static function clientFactory(ClientInterface $http, RequestFactoryInterface&StreamFactoryInterface $factory): \Closure
    {
        return static fn(HostPolicy $policy): IndexNowClient => new IndexNowClient($http, $factory, $factory, null, $policy);
    }
}
