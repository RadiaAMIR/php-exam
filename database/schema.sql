SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS eventhub_db
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE eventhub_db;

DROP TABLE IF EXISTS users;
CREATE TABLE users (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(150) NOT NULL,
    email      VARCHAR(255) NOT NULL UNIQUE,
    password   VARCHAR(255) NOT NULL,
    role       ENUM('organizer', 'participant') NOT NULL DEFAULT 'participant',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS categories;
CREATE TABLE categories (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug          VARCHAR(50)  NOT NULL UNIQUE,
    label         VARCHAR(100) NOT NULL,
    color_primary VARCHAR(7)   NOT NULL DEFAULT '#2563EB',
    color_light   VARCHAR(7)   NOT NULL DEFAULT '#DBEAFE'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS events;
CREATE TABLE events (
    id              INT UNSIGNED      AUTO_INCREMENT PRIMARY KEY,
    title           VARCHAR(255)      NOT NULL,
    description     TEXT              NOT NULL,
    event_date      DATETIME          NOT NULL,
    location        VARCHAR(255)      NOT NULL,
    capacity        SMALLINT UNSIGNED NOT NULL CHECK (capacity > 0),
    category        VARCHAR(50)       NOT NULL,
    organizer_email VARCHAR(255)      NOT NULL,
    organizer_id    INT UNSIGNED      NULL,
    alert_sent      TINYINT(1)        NOT NULL DEFAULT 0,
    created_at      DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Choix : RESTRICT — un événement ayant des inscrits ne peut pas être supprimé
-- accidentellement. L'organisateur doit d'abord gérer les inscriptions.
DROP TABLE IF EXISTS registrations;
CREATE TABLE registrations (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id      INT UNSIGNED NOT NULL,
    name          VARCHAR(150) NOT NULL,
    email         VARCHAR(255) NOT NULL,
    token         VARCHAR(64)  NOT NULL UNIQUE,
    registered_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE RESTRICT,
    UNIQUE KEY uq_event_email (event_id, email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS mail_logs;
CREATE TABLE mail_logs (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type          ENUM('confirmation', 'capacity_alert', 'ticket', 'other') NOT NULL,
    recipient     VARCHAR(255) NOT NULL,
    event_id      INT UNSIGNED NULL,
    error_message TEXT         NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Index composé : optimise searchEvents() qui filtre souvent par date ET catégorie
-- Sans index → MySQL parcourt toute la table. Avec index → accès direct aux lignes.
CREATE INDEX idx_events_date_category ON events (event_date, category);

-- ── Données de test ───────────────────────────────────────────────────────

INSERT INTO categories (slug, label, color_primary, color_light) VALUES
    ('tech',     'Tech',     '#2563EB', '#DBEAFE'),
    ('design',   'Design',   '#7C3AED', '#EDE9FE'),
    ('business', 'Business', '#EA580C', '#FEF3C7'),
    ('science',  'Science',  '#16A34A', '#DCFCE7');

-- Mot de passe : "password"  (hash bcrypt standard de test — changer en prod)
INSERT INTO users (name, email, password, role) VALUES
    ('Organisateur ENSA', 'orga@ensa.ma',       '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'organizer'),
    ('Yassine El Fassi',  'yassine@example.ma', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'participant'),
    ('Salma Benali',      'salma@example.ma',   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'participant'),
    ('Mehdi Khalil',      'mehdi@example.ma',   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'participant'),
    ('Zineb Moussaoui',   'zineb@example.ma',   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'participant');

-- Événements du prof mis à jour en 2026
INSERT INTO events (title, description, event_date, location, capacity, category, organizer_email, organizer_id) VALUES
    ('DevFest Marrakech 2026',
     'La grande conférence tech de Marrakech. Talks, ateliers pratiques et networking avec les professionnels du secteur.',
     '2026-09-20 09:00:00', 'ENSA Marrakech — Grand Amphi', 200, 'tech', 'orga@ensa.ma', 1),
    ('UX Design Workshop 2026',
     'Atelier intensif de design UX : prototypage Figma, tests utilisateurs, design systems. Places très limitées.',
     '2026-07-28 14:00:00', 'École Nationale des Arts, Marrakech', 30, 'design', 'orga@ensa.ma', 1),
    ('PHP & MVC Day 2026',
     'Journée dédiée à PHP 8.x, architecture MVC native, bonnes pratiques PDO et sécurité des applications web.',
     '2026-11-08 09:30:00', 'ENSA Marrakech — Salle TP Informatique', 5, 'tech', 'orga@ensa.ma', 1);

-- 5 inscriptions de test avec tokens uniques
INSERT INTO registrations (event_id, name, email, token) VALUES
    (1, 'Yassine El Fassi', 'yassine@example.ma', 'tok_a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1'),
    (1, 'Salma Benali',     'salma@example.ma',   'tok_b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2'),
    (1, 'Mehdi Khalil',     'mehdi@example.ma',   'tok_c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3'),
    (2, 'Zineb Moussaoui',  'zineb@example.ma',   'tok_d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4'),
    (3, 'Yassine El Fassi', 'yassine@example.ma', 'tok_e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5');

SET FOREIGN_KEY_CHECKS = 1;
