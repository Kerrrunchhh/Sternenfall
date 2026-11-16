<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Mails sent in one hour (UTC), counted per recipient like Gmail's daily limit – blind copies of
 * a broadcast count each. Written by App\EventListener\MailCounter, shown on the admin game page.
 */
#[ORM\Entity(repositoryClass: \App\Repository\MailStatRepository::class)]
class MailStat
{
    #[ORM\Id]
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $hour;

    /** Mails handed to the mail server. */
    #[ORM\Column(options: ['default' => 0])]
    private int $mails = 0;

    /** Recipients of those mails (to, cc and bcc). */
    #[ORM\Column(options: ['default' => 0])]
    private int $recipients = 0;

    /** Recipients of mails the server refused (e.g. limit reached, wrong password). */
    #[ORM\Column(options: ['default' => 0])]
    private int $failed = 0;

    public function __construct(\DateTimeImmutable $hour)
    {
        $this->hour = $hour;
    }

    public function getHour(): \DateTimeImmutable
    {
        return $this->hour;
    }

    public function getMails(): int
    {
        return $this->mails;
    }

    public function getRecipients(): int
    {
        return $this->recipients;
    }

    public function getFailed(): int
    {
        return $this->failed;
    }
}
