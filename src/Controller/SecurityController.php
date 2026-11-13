<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationFormType;
use App\Game\Colonizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

use function Symfony\Component\Translation\t;

class SecurityController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function home(\Symfony\Component\HttpFoundation\Request $request, \App\Game\GameStats $stats): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_overview');
        }
        $deleted = $request->query->getString('geloescht');

        return $this->render('security/home.html.twig', [
            'top' => $stats->topExperienceToday(),
            'deleted_at' => ctype_digit($deleted) ? (new \DateTimeImmutable('@'.$deleted)) : null,
        ]);
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

    /** The link from the confirmation mail. */
    #[Route('/email/bestaetigen', name: 'app_verify_email', methods: ['GET'])]
    public function verifyEmail(Request $request, \Symfony\Component\HttpFoundation\UriSigner $signer, \App\Repository\UserRepository $users, EntityManagerInterface $em): Response
    {
        $user = ctype_digit($id = $request->query->getString('id')) ? $users->find((int) $id) : null;
        if (!$signer->checkRequest($request) || null === $user || $user->getEmail() !== $request->query->getString('email')) {
            $this->addFlash('error', t('Der Bestätigungslink ist ungültig oder abgelaufen. Fordere unten einen neuen an.'));

            return $this->redirectToRoute('app_resend_email');
        }
        $user->verifyEmail(new \DateTimeImmutable());
        $em->flush();
        $this->addFlash('success', t('Danke! Deine E-Mail-Adresse ist bestätigt. Melde dich jetzt an – deine Heimatwelt wartet.'));

        return $this->redirectToRoute('app_login');
    }

    #[Route('/email/erneut', name: 'app_resend_email', methods: ['GET', 'POST'])]
    public function resendEmail(Request $request, \App\Repository\UserRepository $users, \App\Game\GameMailer $mailer): Response
    {
        $devLink = null;
        if ($request->isMethod('POST') && $this->isCsrfTokenValid('resend_email', $request->request->getString('_token'))) {
            $user = $users->findOneBy(['email' => mb_strtolower(trim($request->request->getString('email')))]);
            if (null !== $user && $user->needsEmailConfirmation()) {
                $link = $mailer->sendConfirmation($user);
                $devLink = 'dev' === $this->getParameter('kernel.environment') ? $link : null;
            }
            // Same answer either way: nobody learns which addresses are registered.
            $this->addFlash('success', t('Falls zu dieser Adresse ein unbestätigtes Konto gehört, ist ein neuer Link unterwegs.'));
        }

        return $this->render('security/resend_email.html.twig', ['dev_link' => $devLink]);
    }

    #[Route('/passwort/vergessen', name: 'app_forgot_password', methods: ['GET', 'POST'])]
    public function forgotPassword(
        Request $request,
        \App\Repository\UserRepository $users,
        \App\Security\PasswordReset $reset,
        \App\Game\GameMailer $mailer,
        #[\Symfony\Component\DependencyInjection\Attribute\Target('password_reset')] \Symfony\Component\RateLimiter\RateLimiterFactoryInterface $limiter,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_overview');
        }
        $devLink = null;
        if ($request->isMethod('POST') && $this->isCsrfTokenValid('forgot_password', $request->request->getString('_token'))) {
            if (!$limiter->create((string) $request->getClientIp())->consume()->isAccepted()) {
                $this->addFlash('error', t('Zu viele Anfragen. Versuche es in einer Stunde noch einmal.'));

                return $this->redirectToRoute('app_forgot_password');
            }
            $user = $users->findOneBy(['email' => mb_strtolower(trim($request->request->getString('email')))]);
            if (null !== $user && !$user->isSystem()) {
                $link = $reset->link($user);
                $mailer->sendPasswordReset($user, $link);
                $devLink = 'dev' === $this->getParameter('kernel.environment') ? $link : null;
            }
            // Same answer either way: nobody learns which addresses are registered.
            $this->addFlash('success', t('Falls zu dieser Adresse ein Konto gehört, ist ein Link zum Zurücksetzen unterwegs. Er ist 1 Stunde gültig.'));
        }

        return $this->render('security/forgot_password.html.twig', ['dev_link' => $devLink]);
    }

    /** The link from the reset mail: choose a new password. */
    #[Route('/passwort/neu', name: 'app_reset_password', methods: ['GET', 'POST'])]
    public function resetPassword(Request $request, \App\Security\PasswordReset $reset, UserPasswordHasherInterface $hasher, EntityManagerInterface $em): Response
    {
        $user = $reset->userFor($request);
        if (null === $user) {
            $this->addFlash('error', t('Der Link ist ungültig, abgelaufen oder schon benutzt. Fordere einen neuen an.'));

            return $this->redirectToRoute('app_forgot_password');
        }
        $form = $this->createForm(\App\Form\ResetPasswordFormType::class);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $user->setPassword($hasher->hashPassword($user, $form->get('plainPassword')->getData()));
            // Whoever got the link also owns the address: it counts as confirmed.
            if ($user->needsEmailConfirmation()) {
                $user->verifyEmail(new \DateTimeImmutable());
            }
            $em->flush();
            $this->addFlash('success', t('Dein Passwort ist geändert. Melde dich jetzt mit dem neuen an.'));

            return $this->redirectToRoute('app_login');
        }

        return $this->render('security/reset_password.html.twig', ['form' => $form]);
    }

    #[Route('/register', name: 'app_register')]
    public function register(
        Request $request,
        UserPasswordHasherInterface $hasher,
        EntityManagerInterface $em,
        Colonizer $colonizer,
        \App\Game\GameMailer $mailer,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_overview');
        }

        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->setPassword($hasher->hashPassword($user, $form->get('plainPassword')->getData()));
            // Messages and e-mails go out in the language the player signed up in.
            $user->setLocale($request->getLocale());
            // Chosen life form from the very start (the 30-day change cooldown counts from the sign-up).
            $user->changeLifeForm($form->get('lifeForm')->getData(), $user->getCreatedAt());
            $user->acceptTerms($user->getCreatedAt());
            $em->persist($user);
            $colonizer->createHomePlanet($user);
            $em->flush();
            $link = $mailer->sendConfirmation($user);

            // Playing starts after confirming the e-mail address.
            return $this->render('security/confirm_email.html.twig', [
                'email' => $user->getEmail(),
                'dev_link' => 'dev' === $this->getParameter('kernel.environment') ? $link : null,
            ]);
        }

        return $this->render('security/register.html.twig', ['form' => $form, 'life_forms' => \App\Game\LifeForm::cases()]);
    }
}
