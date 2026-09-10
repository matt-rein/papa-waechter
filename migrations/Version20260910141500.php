<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Nachmeldung von Tipps, die beim ersten Versuch den Server nicht erreicht haben.
 *
 * Anlass: am 10.09.2026 hat Papa nach einem Neustart des iPads getippt, und
 * nichts kam an. Die Seite merkt sich einen Tipp jetzt, bis der Server ihn
 * bestätigt, und schickt ihn notfalls später nach.
 *
 * tap_id:      Kennung, die das iPad pro Tipp vergibt. Kommt derselbe Tipp
 *              zweimal an (Antwort ging verloren, Seite schickt erneut), wird
 *              er nur einmal gespeichert.
 * received_at: wann der Tipp beim Server ankam. occurred_at bleibt der
 *              Zeitpunkt des Tippens – der zählt für den Alarm.
 *
 * Bestehende Zeilen bleiben leer: für sie ist Ankunft gleich Tippen, und eine
 * Kennung hatten sie nie.
 */
final class Version20260910141500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'check_in.tap_id + received_at: nachgemeldete Tipps erkennen und nicht doppelt speichern';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE check_in ADD COLUMN tap_id VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE check_in ADD COLUMN received_at DATETIME DEFAULT NULL');
        // Mehrere NULL sind in SQLite erlaubt – alte Zeilen und Tipps ohne
        // JavaScript stören den Index also nicht.
        $this->addSql('CREATE UNIQUE INDEX uniq_checkin_tap ON check_in (watchee_id, tap_id)');
    }

    public function down(Schema $schema): void
    {
        // Braucht SQLite 3.35+. Der Deploy ruft down() nie auf.
        $this->addSql('DROP INDEX uniq_checkin_tap');
        $this->addSql('ALTER TABLE check_in DROP COLUMN received_at');
        $this->addSql('ALTER TABLE check_in DROP COLUMN tap_id');
    }
}
