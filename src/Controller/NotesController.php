<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use App\I18n\Num;

use function Symfony\Component\Translation\t;

/** A private notepad for every player. */
class NotesController extends AbstractGameController
{
    #[Route('/notizen', name: 'app_notes', methods: ['GET'])]
    public function index(): Response
    {
        return $this->renderGame('game/notes.html.twig', $this->currentPlanet(), ['limit' => User::NOTES_LIMIT]);
    }

    #[Route('/notizen', name: 'app_notes_save', methods: ['POST'])]
    #[IsCsrfTokenValid('notes', tokenKey: '_token')]
    public function save(Request $request, EntityManagerInterface $em): Response
    {
        $this->currentPlanet();
        $notes = str_replace("\r\n", "\n", $request->request->getString('notes'));
        if (mb_strlen($notes) > User::NOTES_LIMIT) {
            $this->addFlash('error', t('Der Notizblock fasst höchstens {value} Zeichen – der Rest wurde abgeschnitten.', ['{value}' => new Num(User::NOTES_LIMIT)]));
        } else {
            $this->addFlash('success', t('Notizen gespeichert.'));
        }
        $this->player()->writeNotes($notes, \DateTimeImmutable::createFromInterface($this->clock->now()));
        $em->flush();

        return $this->redirectToRoute('app_notes');
    }
}
