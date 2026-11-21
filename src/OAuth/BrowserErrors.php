<?php

declare(strict_types=1);

namespace PhpSso\OAuth;

use PhpSso\Pages;
use PhpSso\OAuthServer\Exception\OAuthException;
use PhpSso\OAuthServer\Http\ErrorResponder;
use Psr\Http\Message\ResponseInterface;

/**
 * What a person sees when an authorization or sign-out request cannot be sent back to the app, for instance because
 * its redirect URI is not registered. It is the themable `error` page; an API client never gets this, it gets JSON.
 */
final class BrowserErrors implements ErrorResponder
{
    public function __construct(private Pages $pages)
    {
    }

    public function respond(OAuthException $error): ResponseInterface
    {
        return $this->pages->render('error', [
            'title' => "This sign-in request can't be completed",
            'message' => $error->errorDescription,
            'code' => $error->error,
            'user' => null,
            'csrf' => '',
        ], $error->httpStatus)->withHeader('Cache-Control', 'no-store');
    }
}
