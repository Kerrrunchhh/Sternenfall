<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationFormType;
use App\Game\Colonizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function home(): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_overview');
        }

        return $this->render('security/home.html.twig');
    }

    #[Route('/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_overview');
        }

        return $this->render('security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): never
    {
        throw new \LogicException('Handled by the firewall.');
    }

    #[Route('/register', name: 'app_register')]
    public function register(
        Request $request,
        UserPasswordHasherInterface $hasher,
        EntityManagerInterface $em,
        Colonizer $colonizer,
        Security $security,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_overview');
        }

        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->setPassword($hasher->hashPassword($user, $form->get('plainPassword')->getData()));
            $em->persist($user);
            $colonizer->createHomePlanet($user);
            $em->flush();

            $this->addFlash('success', 'Willkommen, Kommandant! Deine Heimatwelt wartet auf dich.');

            return $security->login($user, 'form_login', 'main') ?? $this->redirectToRoute('app_overview');
        }

        return $this->render('security/register.html.twig', ['form' => $form]);
    }
}
