<?php

namespace App\Repository;

use App\Entity\Message;
use App\Entity\User;
use App\Game\MessageFolder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Message>
 */
class MessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Message::class);
    }

    public function countUnread(User $recipient, ?MessageFolder $folder = null): int
    {
        return $this->count(self::criteria($recipient, $folder) + ['isRead' => false]);
    }

    /** @return array<string, int> folder value => unread messages in it */
    public function countUnreadByFolder(User $recipient): array
    {
        $counts = [];
        foreach ($this->createQueryBuilder('m')->select('m.folder, COUNT(m.id) AS n')
            ->where('m.recipient = :recipient AND m.isRead = false')->setParameter('recipient', $recipient)
            ->groupBy('m.folder')->getQuery()->getArrayResult() as $row) {
            $counts[(string) $row['folder']] = (int) $row['n'];
        }

        return $counts;
    }

    /** @return list<Message> newest first, of one folder or of all */
    public function findLatest(User $recipient, int $limit = 100, int $offset = 0, ?MessageFolder $folder = null): array
    {
        return $this->findBy(self::criteria($recipient, $folder), ['createdAt' => 'DESC', 'id' => 'DESC'], $limit, $offset);
    }

    public function countFor(User $recipient, ?MessageFolder $folder = null): int
    {
        return $this->count(self::criteria($recipient, $folder));
    }

    /** @return array<string, mixed> */
    private static function criteria(User $recipient, ?MessageFolder $folder): array
    {
        return ['recipient' => $recipient] + (null === $folder ? [] : ['folder' => $folder->value]);
    }

    /** Messages are kept for 30 days. */
    public const int KEEP_DAYS = 30;

    public function deleteOlderThan(\DateTimeImmutable $limit): int
    {
        return $this->createQueryBuilder('m')
            ->delete()
            ->where('m.createdAt < :limit')
            ->setParameter('limit', $limit)
            ->getQuery()
            ->execute();
    }

    /** All messages read – of one folder, or of all. */
    public function markAllRead(User $recipient, ?MessageFolder $folder = null): void
    {
        $query = $this->createQueryBuilder('m')
            ->update()
            ->set('m.isRead', 'true')
            ->where('m.recipient = :recipient')
            ->setParameter('recipient', $recipient);
        if (null !== $folder) {
            $query->andWhere('m.folder = :folder')->setParameter('folder', $folder->value);
        }
        $query->getQuery()->execute();
    }
}
