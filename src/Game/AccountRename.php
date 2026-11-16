<?php

namespace App\Game;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

use function Symfony\Component\Translation\t;

/**
 * An admin renames a player's account. The new name follows the sign-up rules and must be
 * free – also in other upper/lower case – and no system account's name. The player is told.
 */
final readonly class AccountRename
{
    public function __construct(
        private UserRepository $users,
        private EntityManagerInterface $em,
        private ValidatorInterface $validator,
        private Inbox $inbox,
    ) {
    }

    /** @return string the old name */
    public function rename(User $user, string $name): string
    {
        $name = trim($name);
        $old = $user->getUsername();
        if ($name === $old) {
            throw new GameException(t('Das ist schon sein Name.'));
        }
        $errors = $this->validator->validatePropertyValue(User::class, 'username', $name);
        if (\count($errors) > 0) {
            throw new GameException(t('Ungültiger Name: {reason}', ['{reason}' => (string) $errors[0]->getMessage()]));
        }
        if (0 === strcasecmp($name, TradeGuild::NAME)) {
            throw new GameException(t('Diesen Namen trägt ein Systemkonto.'));
        }
        $other = $this->users->findOneByNameIgnoringCase($name);
        if (null !== $other && !$other->isSameAs($user)) {
            throw new GameException(t('Den Namen „{name}“ hat schon {player}.', ['{name}' => $name, '{player}' => $other->getUsername()]));
        }

        $user->setUsername($name);
        $this->inbox->send($user, t('Neuer Spielername: {name}', ['{name}' => $name]), t('Die Spielleitung hat deinen Spielernamen von „{old}“ in „{name}“ geändert. Melde dich ab jetzt mit dem neuen Namen an.', ['{old}' => $old, '{name}' => $name]));
        $this->em->flush();

        return $old;
    }
}
