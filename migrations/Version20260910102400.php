<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Speichert, was der Browser beim Bestätigen über sich meldet.
 *
 * Anlass: Safari auf dem iPad sendet seit iPadOS 13 dieselbe Kennung wie ein
 * Mac. Das Protokoll behauptete deshalb "Macintosh" für Papas iPad – das
 * einzige Gerät, um das es hier überhaupt geht.
 *
 * Bestehende Zeilen bleiben leer. Sie nachträglich zu füllen hieße, Messwerte
 * zu erfinden, die nie gemessen wurden; sie werden im Protokoll stattdessen
 * ehrlich als "Mac oder iPad" ausgewiesen.
 */
final class Version20260910102400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'check_in.device_hint: Geräte-Angabe des Browsers (Touch-Punkte, Homescreen-Start)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE check_in ADD COLUMN device_hint VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Braucht SQLite 3.35+. Der Deploy ruft down() nie auf.
        $this->addSql('ALTER TABLE check_in DROP COLUMN device_hint');
    }
}
