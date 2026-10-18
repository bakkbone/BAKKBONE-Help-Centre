<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Mail;

use PHPMailer\PHPMailer\OAuthTokenProvider;

final class XOAuth2Token implements OAuthTokenProvider
{
    public function __construct(private string $user, private string $token)
    {
    }

    public function getOauth64(): string
    {
        return base64_encode("user={$this->user}\x01auth=Bearer {$this->token}\x01\x01");
    }
}
