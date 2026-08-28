<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Das Foto-Portfolio: die Familienbilder, die Papa auf seiner Check-in-Seite
 * als Hintergrund sieht – jeden Tag ein anderes.
 *
 * var/photos ist bewusst ein ganz normaler Ordner: Bilder dürfen dort per
 * Finder hineingezogen werden, mit ihren eigenen Namen, und werden nicht
 * angefasst. Verkleinerte Fassungen und Vorschaubilder entstehen bei Bedarf in
 * einem getrennten Cache-Ordner – so bleibt der Bilder-Ordner sauber und die
 * Originale unberührt.
 *
 * Der Ordner liegt unter var/, also im persistenten Docker-Volume und nicht im
 * Image, und bewusst NICHT unter public/: die Bilder sind privat und werden nur
 * über die Controller ausgeliefert.
 */
class PhotoLibrary
{
    /**
     * Bildformate, die jeder Browser anzeigen kann.
     */
    private const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    /**
     * Formate, die iPhones von Haus aus liefern, die aber kein Browser anzeigt.
     */
    private const HEIC_EXTENSIONS = ['heic', 'heif'];

    /** Längste Kante der Fassung, die an Papas iPad geht. */
    private const DISPLAY_EDGE = 2560;

    /** Längste Kante der Vorschaubilder in der Verwaltung. */
    private const THUMB_EDGE = 400;

    /**
     * Oberhalb dieser Pixelzahl wird nicht mehr umgerechnet – lieber das
     * Original ausliefern als dem Server den Speicher sprengen.
     */
    private const MAX_PIXELS = 50_000_000;

    public function __construct(
        private readonly string $photoDir,
        private readonly string $photoCacheDir,
    ) {
    }

    /**
     * Alle Bilder im Ordner, alphabetisch – egal ob hochgeladen oder von Hand
     * hineingezogen.
     *
     * @return list<string> Dateinamen ohne Pfad
     */
    public function names(): array
    {
        if (!is_dir($this->photoDir)) {
            return [];
        }

        $names = [];
        foreach (scandir($this->photoDir) ?: [] as $entry) {
            if ($this->isUsableName($entry) && is_file($this->photoDir.'/'.$entry)) {
                $names[] = $entry;
            }
        }

        // Nach Namen sortieren, damit die Reihenfolge nicht von der
        // Dateisystem-Laune abhängt.
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);

        return $names;
    }

    /**
     * Bilder für die Verwaltungsseite – neueste zuerst.
     *
     * @return list<array{name: string, size: int, addedAt: \DateTimeImmutable}>
     */
    public function listNewestFirst(): array
    {
        $items = [];
        foreach ($this->names() as $name) {
            $path = $this->photoDir.'/'.$name;
            $items[] = [
                'name' => $name,
                'size' => (int) filesize($path),
                'addedAt' => (new \DateTimeImmutable())->setTimestamp((int) filemtime($path)),
            ];
        }

        usort($items, static fn (array $a, array $b) => $b['addedAt'] <=> $a['addedAt']);

        return $items;
    }

    /**
     * Dateien im Ordner, die kein anzeigbares Bild sind – z. B. HEIC vom
     * iPhone oder versehentlich abgelegte Dokumente. Werden in der Verwaltung
     * angezeigt, damit man nicht rätselt, warum ein Bild nicht auftaucht.
     *
     * @return list<array{name: string, reason: string}>
     */
    public function listIgnored(): array
    {
        if (!is_dir($this->photoDir)) {
            return [];
        }

        $ignored = [];
        foreach (scandir($this->photoDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }
            $path = $this->photoDir.'/'.$entry;
            if (!is_file($path) || $this->isUsableName($entry)) {
                continue;
            }

            $extension = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            $ignored[] = [
                'name' => $entry,
                'reason' => in_array($extension, self::HEIC_EXTENSIONS, true)
                    ? 'HEIC-Bilder kann kein Browser anzeigen – am iPhone unter Einstellungen › Kamera › Formate auf „Maximale Kompatibilität“ stellen oder als JPEG exportieren.'
                    : 'Kein Bild (erlaubt sind JPG, PNG, WebP und GIF).',
            ];
        }

        return $ignored;
    }

    /**
     * Das Bild des Tages. Innerhalb eines Kalendertages immer dasselbe (Papa
     * darf die Seite mehrfach öffnen), am nächsten Tag ein anderes.
     */
    public function pickForDay(\DateTimeImmutable $localDay): ?string
    {
        $names = $this->names();
        $count = count($names);
        if ($count === 0) {
            return null;
        }

        // Fortlaufende Tagesnummer aus dem *Kalenderdatum* – so bleibt die
        // Rechnung von Sommer-/Winterzeit unberührt.
        $civilMidnight = new \DateTimeImmutable(
            $localDay->format('Y-m-d').' 00:00:00',
            new \DateTimeZone('UTC'),
        );
        $dayNumber = intdiv($civilMidnight->getTimestamp(), 86400);

        return $this->orderForCycle($names, intdiv($dayNumber, $count))[$dayNumber % $count];
    }

    /**
     * Pfad zum unveränderten Original.
     */
    public function path(string $name): ?string
    {
        if (!$this->isUsableName($name)) {
            return null;
        }

        $path = $this->photoDir.'/'.$name;

        return is_file($path) ? $path : null;
    }

    /**
     * Fassung für Papas Seite: höchstens 2560 px lange Kante. Kleine Bilder
     * werden unverändert durchgereicht.
     */
    public function displayPath(string $name): ?string
    {
        return $this->variant($name, self::DISPLAY_EDGE);
    }

    /**
     * Vorschaubild für die Verwaltung.
     */
    public function thumbPath(string $name): ?string
    {
        return $this->variant($name, self::THUMB_EDGE);
    }

    /**
     * Nimmt eine hochgeladene Datei auf – unter ihrem eigenen Namen, damit die
     * Verwaltung und der Finder dasselbe zeigen. Wirft eine \RuntimeException
     * mit einer Meldung, die man einem Menschen zeigen kann.
     */
    public function store(UploadedFile $file): string
    {
        $original = $file->getClientOriginalName();

        if (!$file->isValid()) {
            throw new \RuntimeException(sprintf(
                '„%s“ konnte nicht hochgeladen werden: %s',
                $original,
                $file->getErrorMessage(),
            ));
        }

        $extension = strtolower($file->getClientOriginalExtension() ?: pathinfo($original, PATHINFO_EXTENSION));

        if (in_array($extension, self::HEIC_EXTENSIONS, true)) {
            throw new \RuntimeException(sprintf(
                '„%s“ ist ein HEIC-Bild – das können Browser nicht anzeigen. '
                .'Am iPhone unter Einstellungen › Kamera › Formate auf „Maximale Kompatibilität“ '
                .'umstellen oder das Bild vorher als JPEG exportieren.',
                $original,
            ));
        }

        if (!in_array($extension, self::EXTENSIONS, true)) {
            throw new \RuntimeException(sprintf(
                '„%s“ ist kein unterstütztes Bild (JPG, PNG, WebP oder GIF).',
                $original,
            ));
        }

        // Zweite Meinung: ist es wirklich ein Bild und nicht nur so benannt?
        if (@getimagesize($file->getPathname()) === false) {
            throw new \RuntimeException(sprintf('„%s“ lässt sich nicht als Bild lesen.', $original));
        }

        $this->ensureDirectories();

        $name = $this->freeName($original, $extension);
        $file->move($this->photoDir, $name);

        return $name;
    }

    public function delete(string $name): bool
    {
        $path = $this->path($name);
        if ($path === null) {
            return false;
        }

        @unlink($path);
        $this->forgetVariants($name);

        return true;
    }

    /**
     * Der Ordner, in den die Bilder gehören – für Anzeige und Hinweise.
     */
    public function directory(): string
    {
        return $this->photoDir;
    }

    /**
     * Brauchbar ist eine Datei, deren Name ein reiner Dateiname ist (kein Pfad,
     * kein versteckter Punkt-Name) und die eine Bild-Endung trägt. Alles andere
     * – auch "../../.env" – fällt hier durch.
     */
    private function isUsableName(string $name): bool
    {
        if ($name === '' || $name !== basename($name) || str_starts_with($name, '.')) {
            return false;
        }

        // Steuerzeichen und Verzeichnistrenner haben in einem Dateinamen nichts
        // verloren.
        if (preg_match('/[\x00-\x1f\/\\\\]/', $name)) {
            return false;
        }

        return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::EXTENSIONS, true);
    }

    /**
     * Freier Dateiname im Bilder-Ordner. Behält den Namen der Vorlage bei und
     * hängt bei Bedarf "-2", "-3" … an.
     */
    private function freeName(string $original, string $extension): string
    {
        $base = pathinfo(basename($original), PATHINFO_FILENAME);
        $base = preg_replace('/[\x00-\x1f\/\\\\]/', '', $base) ?? '';
        $base = ltrim(trim($base), '.');
        $base = mb_substr($base, 0, 80);

        if ($base === '') {
            $base = 'foto';
        }

        $candidate = $base.'.'.$extension;
        $counter = 2;
        while (file_exists($this->photoDir.'/'.$candidate)) {
            $candidate = $base.'-'.$counter.'.'.$extension;
            ++$counter;
        }

        return $candidate;
    }

    /**
     * Die Reihenfolge, in der das Portfolio in einem Durchlauf gezeigt wird.
     *
     * @param list<string> $names
     *
     * @return list<string>
     */
    private function orderForCycle(array $names, int $cycle): array
    {
        $order = $this->shuffle($names, $cycle);

        // Am Übergang zweier Durchläufe könnte dasselbe Bild zufällig zweimal
        // hintereinander drankommen – das wäre für Papa kein neues Bild. Also
        // in dem Fall die ersten beiden tauschen.
        if (count($order) > 1) {
            $previous = $this->shuffle($names, $cycle - 1);
            if ($order[0] === $previous[count($previous) - 1]) {
                [$order[0], $order[1]] = [$order[1], $order[0]];
            }
        }

        return $order;
    }

    /**
     * Deterministisches Mischen: gleiche Eingabe -> gleiche Reihenfolge, ohne
     * den globalen Zufallsgenerator anzufassen.
     *
     * @param list<string> $names
     *
     * @return list<string>
     */
    private function shuffle(array $names, int $cycle): array
    {
        usort($names, static fn (string $a, string $b) => strcmp(
            md5($cycle.'|'.$a),
            md5($cycle.'|'.$b),
        ));

        return $names;
    }

    /**
     * Liefert eine auf $maxEdge verkleinerte Fassung aus dem Cache und legt sie
     * bei Bedarf an. Fällt auf das Original zurück, wenn das Bild ohnehin klein
     * genug ist oder sich nicht umrechnen lässt (z. B. ohne GD).
     */
    private function variant(string $name, int $maxEdge): ?string
    {
        $source = $this->path($name);
        if ($source === null) {
            return null;
        }

        $info = @getimagesize($source);
        if ($info === false) {
            return $source;
        }

        [$width, $height] = $info;

        if (max($width, $height) <= $maxEdge || ($width * $height) > self::MAX_PIXELS) {
            return $source;
        }

        $mtime = (int) filemtime($source);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $cached = $this->photoCacheDir.'/'.sha1($name).'-'.$mtime.'-'.$maxEdge.'.'.$extension;

        if (is_file($cached)) {
            return $cached;
        }

        return $this->writeResized($source, (string) $info['mime'], $cached, $maxEdge)
            ? $cached
            : $source;
    }

    /**
     * Räumt die Cache-Dateien eines gelöschten Bildes weg.
     */
    private function forgetVariants(string $name): void
    {
        foreach (glob($this->photoCacheDir.'/'.sha1($name).'-*') ?: [] as $stale) {
            @unlink($stale);
        }
    }

    /**
     * Verkleinert ein Bild auf die angegebene längste Kante und dreht es dabei
     * gemäß EXIF gerade. Gibt false zurück, wenn das nicht möglich ist (z. B.
     * ohne GD) – dann muss der Aufrufer das Original verwenden.
     */
    private function writeResized(string $source, string $mime, string $target, int $maxEdge): bool
    {
        if (!\function_exists('imagecreatetruecolor')) {
            return false;
        }

        $image = match ($mime) {
            'image/jpeg' => \function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($source) : false,
            'image/png' => \function_exists('imagecreatefrompng') ? @imagecreatefrompng($source) : false,
            'image/webp' => \function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : false,
            'image/gif' => \function_exists('imagecreatefromgif') ? @imagecreatefromgif($source) : false,
            default => false,
        };

        if ($image === false) {
            return false;
        }

        try {
            $image = $this->applyExifRotation($image, $source, $mime);

            $width = imagesx($image);
            $height = imagesy($image);
            $longest = max($width, $height);
            $scale = $longest > $maxEdge ? $maxEdge / $longest : 1.0;

            $newWidth = max(1, (int) round($width * $scale));
            $newHeight = max(1, (int) round($height * $scale));

            $resized = imagecreatetruecolor($newWidth, $newHeight);
            // Transparenz von PNG/GIF/WebP erhalten.
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

            imagedestroy($image);
            $image = $resized;

            $directory = \dirname($target);
            if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
                return false;
            }

            return match ($mime) {
                'image/jpeg' => imagejpeg($image, $target, 82),
                'image/png' => imagepng($image, $target, 6),
                'image/webp' => imagewebp($image, $target, 82),
                'image/gif' => imagegif($image, $target),
                default => false,
            };
        } finally {
            if ($image instanceof \GdImage) {
                imagedestroy($image);
            }
        }
    }

    /**
     * Handyfotos tragen die Drehung nur als EXIF-Vermerk. Beim Neuberechnen
     * geht der verloren – also vorher wirklich drehen.
     */
    private function applyExifRotation(\GdImage $image, string $source, string $mime): \GdImage
    {
        if ($mime !== 'image/jpeg' || !\function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($source);
        $orientation = (int) ($exif['Orientation'] ?? 0);

        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => null,
        };

        if ($rotated === null || $rotated === false) {
            return $image;
        }

        imagedestroy($image);

        return $rotated;
    }

    private function ensureDirectories(): void
    {
        foreach ([$this->photoDir, $this->photoCacheDir] as $directory) {
            if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
                throw new \RuntimeException(sprintf('Der Ordner "%s" lässt sich nicht anlegen.', $directory));
            }
        }
    }
}
