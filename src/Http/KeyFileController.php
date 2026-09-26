<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Sylius\Http;

use Koh\IndexNow\Sylius\Activation\ChannelGate;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves "/<key>.txt", the file a search engine fetches to confirm the key.
 *
 * Every other key, and every request on a host that does not announce, gets
 * the shop's ordinary 404 — a development shop has no key file at all, and a
 * wrong guess is not told apart from a missing feature.
 */
final readonly class KeyFileController
{
    public function __construct(private ChannelGate $gate) {}

    public function __invoke(Request $request, string $key): Response
    {
        $activation = $this->gate->forHost($request->getHost());
        if (!$activation->active || $activation->key === null || !$activation->key->isFilePath('/' . $key . '.txt')) {
            throw new NotFoundHttpException();
        }

        $response = new Response($activation->key->value, Response::HTTP_OK, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'X-Robots-Tag' => 'noindex',
        ]);
        $response->setPublic();
        $response->setMaxAge(3600);

        return $response;
    }
}
