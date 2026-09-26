<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Sylius\Activation;

use Koh\IndexNow\Exception\InvalidKey;
use Koh\IndexNow\HostName;
use Koh\IndexNow\HostPolicy;
use Koh\IndexNow\Key;
use Doctrine\Persistence\ObjectRepository;
use Sylius\Component\Core\Model\ChannelInterface;

/**
 * The single place that decides whether a host announces.
 *
 * A host announces when the kernel runs in "prod", the bundle is enabled, an
 * enabled channel has exactly this hostname (and is selected, if channels are
 * configured), the host policy accepts it and a key can be had. "prod" is not
 * configurable: a staging shop that happens to have a public name must not be
 * able to switch itself on.
 */
class ChannelGate
{
    /** @var array<string, Activation> */
    private array $cache = [];

    /**
     * @param ObjectRepository<ChannelInterface> $channels
     * @param list<string> $channelCodes
     * @param list<string> $deniedHostSuffixes
     */
    public function __construct(
        private readonly ObjectRepository $channels,
        private readonly string $environment,
        private readonly bool $enabled,
        private readonly string $explicitKey,
        private readonly string $secret,
        private readonly array $channelCodes = [],
        private readonly array $deniedHostSuffixes = [],
    ) {}

    public function forHost(string $host): Activation
    {
        $host = HostName::normalize($host);

        return $this->cache[$host] ??= $this->evaluate($host);
    }

    /**
     * Every enabled channel with a hostname, keyed by normalised host.
     *
     * @return array<string, ChannelInterface>
     */
    public function channelsByHost(): array
    {
        $byHost = [];
        foreach ($this->channels->findBy(['enabled' => true]) as $channel) {
            $host = HostName::normalize((string) $channel->getHostname());
            if ($host !== '' && !isset($byHost[$host])) {
                $byHost[$host] = $channel;
            }
        }

        return $byHost;
    }

    public function hostPolicy(): HostPolicy
    {
        return HostPolicy::default()->withDeniedSuffixes(...$this->deniedHostSuffixes);
    }

    public function reset(): void
    {
        $this->cache = [];
    }

    private function evaluate(string $host): Activation
    {
        if (!$this->enabled) {
            return Activation::inactive('switched off by koh_indexnow.enabled');
        }
        if ($this->environment !== 'prod') {
            return Activation::inactive(sprintf('the kernel environment is "%s"; only prod announces', $this->environment));
        }

        $channel = $this->channelsByHost()[$host] ?? null;
        if ($channel === null) {
            return Activation::inactive(sprintf('no enabled channel has the hostname "%s"', $host));
        }
        if ($this->channelCodes !== [] && !in_array($channel->getCode(), $this->channelCodes, true)) {
            return Activation::inactive(sprintf('channel "%s" is not listed in koh_indexnow.channels', (string) $channel->getCode()), $channel);
        }

        $policy = $this->hostPolicy();
        $refusal = $policy->refusalReason($host);
        if ($refusal !== null) {
            return Activation::inactive($refusal, $channel);
        }

        try {
            $key = $this->explicitKey !== '' ? Key::fromString($this->explicitKey) : Key::derive($this->secret, $host);
        } catch (InvalidKey $e) {
            return Activation::inactive($e->getMessage(), $channel);
        }

        return Activation::active($key, $channel, $policy);
    }
}
