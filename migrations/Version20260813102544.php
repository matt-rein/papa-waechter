<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260813102544 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE alert_state (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, active BOOLEAN DEFAULT 0 NOT NULL, first_triggered_at DATETIME DEFAULT NULL, last_notified_at DATETIME DEFAULT NULL, watchee_id INTEGER NOT NULL, CONSTRAINT FK_49C92510ECEC47B FOREIGN KEY (watchee_id) REFERENCES watchee (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_49C92510ECEC47B ON alert_state (watchee_id)');
        $this->addSql('CREATE TABLE check_in (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, occurred_at DATETIME NOT NULL, ip_address VARCHAR(45) DEFAULT NULL, user_agent VARCHAR(255) DEFAULT NULL, watchee_id INTEGER NOT NULL, CONSTRAINT FK_90466CF9ECEC47B FOREIGN KEY (watchee_id) REFERENCES watchee (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_90466CF9ECEC47B ON check_in (watchee_id)');
        $this->addSql('CREATE INDEX idx_checkin_occurred ON check_in (occurred_at)');
        $this->addSql('CREATE TABLE guardian (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, email VARCHAR(180) NOT NULL, name VARCHAR(120) NOT NULL, roles CLOB NOT NULL, password VARCHAR(255) NOT NULL, receives_alerts BOOLEAN DEFAULT 1 NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_64486055E7927C74 ON guardian (email)');
        $this->addSql('CREATE TABLE watchee (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(120) NOT NULL, checkin_token VARCHAR(64) NOT NULL, timezone VARCHAR(64) DEFAULT \'Europe/Berlin\' NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_315C636B279610B8 ON watchee (checkin_token)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE alert_state');
        $this->addSql('DROP TABLE check_in');
        $this->addSql('DROP TABLE guardian');
        $this->addSql('DROP TABLE watchee');
    }
}
