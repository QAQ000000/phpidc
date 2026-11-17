<?php

declare(strict_types=1);

namespace App\Drivers\Identity;

use DomainException;
use GuzzleHttp\Exception\GuzzleException;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\RedirectResponse;

class SocialiteDriver
{
    public function __construct(private readonly Provider $provider) {}

    public function redirect(): RedirectResponse
    {
        return $this->provider->redirect();
    }

    public function subject(): string
    {
        try {
            $subject = (string) $this->provider->user()->getId();
        } catch (InvalidStateException|GuzzleException) {
            // Discard provider response bodies/tokens; failure terminates this login attempt.
            throw new DomainException('第三方授权未完成或已过期，请重新发起。');
        }
        if ($subject === '' || strlen($subject) > 128) {
            throw new DomainException('第三方未返回有效的账户标识。');
        }

        return $subject;
    }
}
