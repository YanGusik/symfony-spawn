<?php

namespace Spawn\Symfony\EventListener;

use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Clears all EntityManagers after each request.
 *
 * EntityManager::$unitOfWork holds all loaded entities in memory (identity map).
 * In a long-running worker this map grows without bound across requests.
 * Clearing after terminate() discards the map and frees memory without
 * closing the underlying DB connection (the PDO pool keeps it alive).
 */
#[AsEventListener(event: KernelEvents::TERMINATE)]
class EntityManagerResetListener
{
    public function __construct(private readonly ManagerRegistry $registry) {}

    public function __invoke(): void
    {
        foreach ($this->registry->getManagers() as $em) {
            $conn = $em->getConnection();

            // Roll back any transaction left open by a failed request so the
            // pooled connection is clean for the next coroutine.
            while ($conn->getTransactionNestingLevel() > 1) {
                $conn->rollBack();
            }
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }

            $em->clear();
        }
    }
}
