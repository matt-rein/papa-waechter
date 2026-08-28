<?php

namespace App\Controller;

use App\Entity\Guardian;
use App\Repository\GuardianRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Verwaltung der Wächter (Dashboard-Logins + Alarm-Empfänger) über die
 * Oberfläche – das Gegenstück zu "app:guardian:create" auf der Kommandozeile.
 *
 * Es gibt bewusst keine Admin-Rolle: alle Wächter sind gleichberechtigt, das
 * ist bei einem Familien-Setup mit zwei, drei Personen die einfachere Wahrheit.
 */
#[IsGranted('ROLE_USER')]
#[Route('/waechter')]
class GuardianController extends AbstractController
{
    /**
     * Mindestlänge für neue Passwörter – gleich wie in den Konsolen-Befehlen.
     */
    private const MIN_PASSWORD_LENGTH = 8;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GuardianRepository $guardians,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('', name: 'app_guardian_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('guardian/index.html.twig', [
            'guardians' => $this->guardians->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route('/neu', name: 'app_guardian_new', methods: ['POST'])]
    public function new(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('guardian_new', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Die Sitzung ist abgelaufen. Bitte noch einmal versuchen.');

            return $this->redirectToRoute('app_guardian_index');
        }

        $email = trim((string) $request->request->get('email'));
        $name = trim((string) $request->request->get('name'));
        $password = (string) $request->request->get('password');
        $receivesAlerts = $request->request->getBoolean('receivesAlerts');

        $guardian = new Guardian();
        $guardian->setEmail($email);
        $guardian->setName($name);
        $guardian->setReceivesAlerts($receivesAlerts);

        $errors = $this->validator->validate($guardian);
        if (count($errors) > 0) {
            foreach ($errors as $error) {
                $this->addFlash('error', $error->getMessage());
            }

            return $this->redirectToRoute('app_guardian_index');
        }

        if (!$this->passwordIsAcceptable($password, (string) $request->request->get('passwordRepeat'))) {
            return $this->redirectToRoute('app_guardian_index');
        }

        $guardian->setPassword($this->hasher->hashPassword($guardian, $password));

        $this->em->persist($guardian);
        $this->em->flush();

        $this->addFlash('success', sprintf('Wächter „%s“ wurde angelegt.', $guardian->getName()));

        return $this->redirectToRoute('app_guardian_index');
    }

    /**
     * Passwort eines anderen Wächters neu setzen (falls jemand seines vergisst).
     */
    #[Route('/{id}/passwort', name: 'app_guardian_password', methods: ['POST'])]
    public function password(Request $request, Guardian $guardian): Response
    {
        if (!$this->isCsrfTokenValid('guardian_password_'.$guardian->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Die Sitzung ist abgelaufen. Bitte noch einmal versuchen.');

            return $this->redirectToRoute('app_guardian_index');
        }

        $password = (string) $request->request->get('password');
        if (!$this->passwordIsAcceptable($password, (string) $request->request->get('passwordRepeat'))) {
            return $this->redirectToRoute('app_guardian_index');
        }

        $guardian->setPassword($this->hasher->hashPassword($guardian, $password));
        $this->em->flush();

        $this->addFlash('success', sprintf('Neues Passwort für „%s“ gespeichert.', $guardian->getName()));

        return $this->redirectToRoute('app_guardian_index');
    }

    /**
     * Eigenes Passwort ändern – hier zusätzlich mit Abfrage des alten Passworts,
     * damit ein offen stehender Browser nicht zur Übernahme des Zugangs reicht.
     */
    #[Route('/eigenes-passwort', name: 'app_guardian_own_password', methods: ['POST'])]
    public function ownPassword(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('guardian_own_password', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Die Sitzung ist abgelaufen. Bitte noch einmal versuchen.');

            return $this->redirectToRoute('app_guardian_index');
        }

        /** @var Guardian $me */
        $me = $this->getUser();

        if (!$this->hasher->isPasswordValid($me, (string) $request->request->get('currentPassword'))) {
            $this->addFlash('error', 'Das aktuelle Passwort stimmt nicht.');

            return $this->redirectToRoute('app_guardian_index');
        }

        $password = (string) $request->request->get('password');
        if (!$this->passwordIsAcceptable($password, (string) $request->request->get('passwordRepeat'))) {
            return $this->redirectToRoute('app_guardian_index');
        }

        $me->setPassword($this->hasher->hashPassword($me, $password));
        $this->em->flush();

        $this->addFlash('success', 'Dein Passwort wurde geändert.');

        return $this->redirectToRoute('app_guardian_index');
    }

    /**
     * Alarm-Mails für einen Wächter an- oder abschalten.
     */
    #[Route('/{id}/alarme', name: 'app_guardian_alerts', methods: ['POST'])]
    public function alerts(Request $request, Guardian $guardian): Response
    {
        if (!$this->isCsrfTokenValid('guardian_alerts_'.$guardian->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Die Sitzung ist abgelaufen. Bitte noch einmal versuchen.');

            return $this->redirectToRoute('app_guardian_index');
        }

        $guardian->setReceivesAlerts(!$guardian->isReceivesAlerts());
        $this->em->flush();

        if ($guardian->isReceivesAlerts()) {
            $this->addFlash('success', sprintf('„%s“ erhält jetzt Alarm-Mails.', $guardian->getName()));
        } else {
            $this->addFlash('success', sprintf('„%s“ erhält keine Alarm-Mails mehr.', $guardian->getName()));
        }

        $this->warnIfNoRecipientsLeft();

        return $this->redirectToRoute('app_guardian_index');
    }

    #[Route('/{id}/loeschen', name: 'app_guardian_delete', methods: ['POST'])]
    public function delete(Request $request, Guardian $guardian): Response
    {
        if (!$this->isCsrfTokenValid('guardian_delete_'.$guardian->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Die Sitzung ist abgelaufen. Bitte noch einmal versuchen.');

            return $this->redirectToRoute('app_guardian_index');
        }

        if ($guardian === $this->getUser()) {
            $this->addFlash('error', 'Du kannst dich nicht selbst löschen.');

            return $this->redirectToRoute('app_guardian_index');
        }

        if (count($this->guardians->findAll()) <= 1) {
            $this->addFlash('error', 'Der letzte Wächter kann nicht gelöscht werden – sonst käme niemand mehr ins Dashboard.');

            return $this->redirectToRoute('app_guardian_index');
        }

        $name = $guardian->getName();
        $this->em->remove($guardian);
        $this->em->flush();

        $this->addFlash('success', sprintf('Wächter „%s“ wurde gelöscht.', $name));

        $this->warnIfNoRecipientsLeft();

        return $this->redirectToRoute('app_guardian_index');
    }

    /**
     * Gemeinsame Prüfung für alle Passwort-Formulare. Meldet Fehler direkt als
     * Flash-Nachricht.
     */
    private function passwordIsAcceptable(string $password, string $repeat): bool
    {
        if ($password !== $repeat) {
            $this->addFlash('error', 'Die beiden Passwort-Eingaben stimmen nicht überein.');

            return false;
        }

        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $this->addFlash('error', sprintf('Bitte ein Passwort mit mindestens %d Zeichen wählen.', self::MIN_PASSWORD_LENGTH));

            return false;
        }

        return true;
    }

    /**
     * Ein System ohne Alarm-Empfänger wäre still im Ernstfall – darauf hinweisen.
     */
    private function warnIfNoRecipientsLeft(): void
    {
        if ($this->guardians->findAlertRecipients() === []) {
            $this->addFlash('error', 'Achtung: Es erhält niemand mehr Alarm-Mails! Bitte mindestens eine Person aktivieren.');
        }
    }
}
