-- ============================================================
-- mediaXm — Esquema de base de datos
-- Motor: MySQL 8.0+
-- ============================================================

CREATE DATABASE IF NOT EXISTS mediaxm_db
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE mediaxm_db;

-- ─── Tabla principal de archivos ────────────────────────────
CREATE TABLE IF NOT EXISTS archivos (
    id            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    nombre        VARCHAR(255)    NOT NULL,
    tipo          ENUM('audio','video','imagen') NOT NULL,
    ruta          VARCHAR(512)    NOT NULL,          -- ruta local (uploads/)
    cloud_id      VARCHAR(255)    NULL,              -- ID en Google Drive
    tamano        BIGINT UNSIGNED NOT NULL DEFAULT 0,-- bytes
    etiquetas     VARCHAR(500)    NOT NULL DEFAULT '',
    compartido    TINYINT(1)      NOT NULL DEFAULT 0,
    metadatos     JSON            NULL,              -- duracion, resolucion, etc.
    fecha_subida  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_mod     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                  ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_tipo     (tipo),
    INDEX idx_etiquetas (etiquetas(100)),
    INDEX idx_fecha    (fecha_subida),
    FULLTEXT INDEX ft_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── Tabla de estadísticas (usada por Observer) ─────────────
CREATE TABLE IF NOT EXISTS estadisticas (
    tipo           VARCHAR(20)     NOT NULL,
    total_archivos INT UNSIGNED    NOT NULL DEFAULT 0,
    total_bytes    BIGINT UNSIGNED NOT NULL DEFAULT 0,
    actualizado_en DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                   ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (tipo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Inicializar contadores
INSERT IGNORE INTO estadisticas (tipo) VALUES ('audio'), ('video'), ('imagen');

-- ─── Tabla de playlists / álbumes ───────────────────────────
CREATE TABLE IF NOT EXISTS colecciones (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre      VARCHAR(255) NOT NULL,
    descripcion TEXT         NULL,
    tipo        ENUM('playlist','album','galeria') NOT NULL DEFAULT 'album',
    creada_en   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── Relación archivos ↔ colecciones ────────────────────────
CREATE TABLE IF NOT EXISTS coleccion_archivos (
    coleccion_id INT UNSIGNED NOT NULL,
    archivo_id   INT UNSIGNED NOT NULL,
    posicion     SMALLINT     NOT NULL DEFAULT 0,
    PRIMARY KEY (coleccion_id, archivo_id),
    FOREIGN KEY (coleccion_id) REFERENCES colecciones(id) ON DELETE CASCADE,
    FOREIGN KEY (archivo_id)   REFERENCES archivos(id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── Datos de ejemplo ───────────────────────────────────────
INSERT INTO archivos (nombre, tipo, ruta, tamano, etiquetas, metadatos) VALUES
('Lo-Fi Vibes.mp3',    'audio',  'demo_1.mp3',  4718592,  'lofi, chill, musica',
 '{"duracion":"3:42","formato":"MP3","bitrate":"192kbps"}'),
('Tutorial PHP.mp4',   'video',  'demo_2.mp4',  52428800, 'php, tutorial, programacion',
 '{"duracion":"15:30","resolucion":"1920x1080","fps":"30"}'),
('Portada Album.png',  'imagen', 'demo_3.png',  2097152,  'arte, album, diseño',
 '{"dimensiones":"3000x3000","formato":"PNG","dpi":"300"}'),
('Jazz Session.wav',   'audio',  'demo_4.wav',  31457280, 'jazz, live, musica',
 '{"duracion":"8:15","formato":"WAV","bitrate":"1411kbps"}'),
('Timelapse Ciudad.mp4','video', 'demo_5.mp4',  78643200, 'ciudad, timelapse, paisaje',
 '{"duracion":"2:00","resolucion":"3840x2160","fps":"60"}'),
('Paisaje Montaña.jpg','imagen', 'demo_6.jpg',  5242880,  'naturaleza, foto, paisaje',
 '{"dimensiones":"6000x4000","formato":"JPEG","dpi":"72"}');
