<?php

namespace App\Controller;

use App\I18n\DisplayZone;
use App\I18n\When;
use App\I18n\Lines;
use App\Game\GameException;
use App\Game\VacationService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

use function Symfony\Component\Translation\t;

/** Account settings: vacation, time zone, e-mail notifications, deleting the account. */
class AccountController extends AbstractGameController
{
    #[Route('/konto', name: 'app_account', methods: ['GET'])]
    public function index(): Response
    {
        return $this->renderGame('game/account.html.twig', $this->currentPlanet(), [
            'vacation_min' => VacationService::MIN_DAYS,
            'vacation_max' => VacationService::MAX_DAYS,
            'topics' => \App\Game\GameMailer::TOPICS,
            'timezones' => self::timezones(new \DateTimeImmutable()),
        ]);
    }

    #[Route('/konto/zeitzone', name: 'app_account_timezone', methods: ['POST'])]
    #[IsCsrfTokenValid('account', tokenKey: '_token')]
    public function timezone(Request $request, \Doctrine\ORM\EntityManagerInterface $em): Response
    {
        $this->currentPlanet();
        $zone = $request->request->getString('timezone');
        if (!DisplayZone::isValid($zone)) {
            $this->addFlash('error', t('Diese Zeitzone kenne ich nicht.'));

            return $this->redirectToRoute('app_account');
        }
        $this->player()->setTimezone($zone);
        $em->flush();
        $this->addFlash('success', t('Zeitzone gespeichert: {zone}. Alle Zeiten werden jetzt darin angezeigt.', ['{zone}' => $zone]));

        return $this->redirectToRoute('app_account', ['_fragment' => 'zeitzone']);
    }

    /**
     * Time zones grouped by region, each with its current offset to UTC (it changes with daylight saving).
     *
     * @return array<string, array<string, string>> region => [zone => label]
     */
    public static function timezones(\DateTimeImmutable $now): array
    {
        $groups = [];
        foreach (\DateTimeZone::listIdentifiers() as $zone) {
            $region = str_contains($zone, '/') ? strstr($zone, '/', true) : 'UTC';
            $groups[$region][$zone] = \sprintf('%s (UTC%s)', str_replace('_', ' ', $zone), $now->setTimezone(new \DateTimeZone($zone))->format('P'));
        }

        return $groups;
    }

    #[Route('/konto/email', name: 'app_account_email', methods: ['POST'])]
    #[IsCsrfTokenValid('account', tokenKey: '_token')]
    public function email(Request $request, \App\Game\GameMailer $mailer, \Doctrine\ORM\EntityManagerInterface $em, \Symfony\Component\Validator\Validator\ValidatorInterface $validator): Response
    {
        $this->currentPlanet();
        $player = $this->player();
        $player->setMailTopics(array_values(array_intersect(array_keys(\App\Game\GameMailer::TOPICS), $request->request->all('topics'))));
        $player->setAttackMailsPerMinute($request->request->getInt('attack_limit', $player->getAttackMailsPerMinute()));
        $address = trim($request->request->getString('email'));
        $changed = '' !== $address && $address !== $player->getEmail();
        if ($changed) {
            $player->setEmail($address);
            $errors = $validator->validate($player);
            if (\count($errors) > 0) {
                $em->refresh($player);
                $this->addFlash('error', $errors[0]->getMessage());

                return $this->redirectToRoute('app_account');
            }
        }
        $em->flush();
        if ($changed || ('resend' === $request->request->getString('do') && $player->needsEmailConfirmation())) {
            $link = $mailer->sendConfirmation($player);
            $this->addFlash('success', new Lines([t('Gespeichert. Bitte bestätige die Adresse mit dem Link aus der Mail, erst dann bekommst du Benachrichtigungen.'), 'dev' === $this->getParameter('kernel.environment') ? '(dev: '.$link.')' : null], ' '));
        } else {
            $this->addFlash('success', t('Einstellungen gespeichert.'));
        }

        return $this->redirectToRoute('app_account');
    }

    #[Route('/konto/loeschen', name: 'app_account_delete', methods: ['POST'])]
    #[IsCsrfTokenValid('account', tokenKey: '_token')]
    public function delete(Request $request, \App\Game\AccountDeletion $deletion, \Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface $hasher, \Symfony\Bundle\SecurityBundle\Security $security): Response
    {
        $this->currentPlanet();
        $player = $this->player();
        if (!$hasher->isPasswordValid($player, $request->request->getString('password'))) {
            $this->addFlash('error', t('Das Passwort stimmt nicht.'));

            return $this->redirectToRoute('app_account');
        }
        $at = $deletion->request($player, 'destroy' === $request->request->getString('planets'));
        $security->logout(false);

        return $this->redirectToRoute('app_home', ['geloescht' => $at->getTimestamp()]);
    }

    #[Route('/konto/urlaub', name: 'app_account_vacation', methods: ['POST'])]
    #[IsCsrfTokenValid('account', tokenKey: '_token')]
    public function vacation(Request $request, VacationService $vacations): Response
    {
        $this->currentPlanet();
        try {
            $until = $vacations->start($this->player(), VacationService::parseDays($request->request->getString('days')));
            $this->addFlash('success', t('Gute Erholung! Dein Urlaub dauert bis {until}.', ['{until}' => new When($until)]));

            return $this->redirectToRoute('app_vacation');
        } catch (GameException $e) {
            $this->addFlash('error', $e->translatable());

            return $this->redirectToRoute('app_account');
        }
    }
}
