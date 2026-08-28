<?php

namespace App\Controller;

use App\Service\PhotoLibrary;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Verwaltung des Foto-Portfolios: hier laden die Wächter die Familienbilder
 * hoch, die Papa auf seiner Check-in-Seite als Hintergrund sieht.
 */
#[IsGranted('ROLE_USER')]
#[Route('/fotos')]
class PhotoController extends AbstractController
{
    /**
     * Dateinamen dürfen beliebig aussehen (die Bilder werden ja auch von Hand
     * in den Ordner gezogen), nur eben keinen Pfad enthalten. Ob der Name
     * wirklich zu einem Bild im Ordner gehört, prüft die PhotoLibrary.
     */
    public const NAME_PATTERN = '[^/]+';

    public function __construct(
        private readonly PhotoLibrary $photos,
        private readonly string $displayTimezone,
        private readonly string $photoDirLabel,
    ) {
    }

    #[Route('', name: 'app_photo_index', methods: ['GET'])]
    public function index(): Response
    {
        $today = new \DateTimeImmutable('now', new \DateTimeZone($this->displayTimezone));

        return $this->render('photo/index.html.twig', [
            'photos' => $this->photos->listNewestFirst(),
            'ignored' => $this->photos->listIgnored(),
            // Nicht der Pfad im Container, sondern der auf dem Rechner davor.
            'directory' => $this->photoDirLabel,
            'today' => $this->photos->pickForDay($today),
            'tomorrow' => $this->photos->pickForDay($today->modify('+1 day')),
        ]);
    }

    #[Route('/hochladen', name: 'app_photo_upload', methods: ['POST'])]
    public function upload(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('photo_upload', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Die Sitzung ist abgelaufen. Bitte noch einmal versuchen.');

            return $this->redirectToRoute('app_photo_index');
        }

        /** @var list<UploadedFile|null> $files */
        $files = $request->files->all()['photos'] ?? [];
        $files = array_filter($files);

        if ($files === []) {
            // Trifft auch zu, wenn post_max_size gerissen wurde – dann kommt der
            // Request ohne Dateien (und ohne Fehlermeldung) hier an.
            $this->addFlash('error', 'Es kam kein Bild an. Entweder war keines ausgewählt, oder die Bilder waren zusammen zu groß – dann bitte in kleineren Portionen hochladen.');

            return $this->redirectToRoute('app_photo_index');
        }

        $stored = 0;
        foreach ($files as $file) {
            try {
                $this->photos->store($file);
                ++$stored;
            } catch (\RuntimeException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        if ($stored > 0) {
            $this->addFlash('success', $stored === 1
                ? 'Ein Bild wurde hinzugefügt.'
                : sprintf('%d Bilder wurden hinzugefügt.', $stored));
        }

        return $this->redirectToRoute('app_photo_index');
    }

    #[Route('/{name}/loeschen', name: 'app_photo_delete', methods: ['POST'], requirements: ['name' => self::NAME_PATTERN])]
    public function delete(Request $request, string $name): Response
    {
        if (!$this->isCsrfTokenValid('photo_delete_'.$name, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Die Sitzung ist abgelaufen. Bitte noch einmal versuchen.');

            return $this->redirectToRoute('app_photo_index');
        }

        if ($this->photos->delete($name)) {
            $this->addFlash('success', 'Das Bild wurde gelöscht.');
        } else {
            $this->addFlash('error', 'Dieses Bild gibt es nicht (mehr).');
        }

        return $this->redirectToRoute('app_photo_index');
    }

    /**
     * Bild-Auslieferung für die Verwaltungsseite (nur angemeldete Wächter).
     */
    #[Route('/{name}/{size}', name: 'app_photo_show', methods: ['GET'], requirements: ['name' => self::NAME_PATTERN, 'size' => 'gross|klein'])]
    public function show(string $name, string $size): Response
    {
        $path = $size === 'klein'
            ? $this->photos->thumbPath($name)
            : $this->photos->displayPath($name);
        if ($path === null) {
            throw $this->createNotFoundException();
        }

        return self::fileResponse($path);
    }

    /**
     * Gemeinsame Auslieferung – auch vom CheckInController genutzt.
     */
    public static function fileResponse(string $path): BinaryFileResponse
    {
        $response = new BinaryFileResponse($path);
        $response->setPrivate();
        // Ein Dateiname zeigt immer auf dasselbe Bild, darf also lange im
        // Browser-Cache bleiben: das iPad lädt das Tagesbild nur einmal.
        $response->setMaxAge(86400);
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
