<?php

namespace Causal\Oidc\Event;

use Psr\Http\Message\ServerRequestInterface;

class GetAuthorizationUrlEvent
{
    public function __construct(
        private readonly ?ServerRequestInterface $request,
        private readonly array $settings,
        private array $options,
    ) {}

    public function getRequest(): ?ServerRequestInterface
    {
        return $this->request;
    }

    public function getSettings(): array
    {
        return $this->settings;
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function setOptions(array $options): void
    {
        $this->options = $options;
    }
}
