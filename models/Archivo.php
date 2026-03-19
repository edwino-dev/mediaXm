<?php
/**
 * ============================================================
 * CLASES BASE DEL MODELO
 * Archivo + subclases: Audio, Video, Imagen
 * ============================================================
 */

abstract class Archivo {
    protected int    $id;
    protected string $nombre;
    protected string $tipo;       // audio | video | imagen
    protected string $ruta;
    protected int    $tamano;     // bytes
    protected string $etiquetas;
    protected string $fechaSubida;
    protected bool   $compartido;
    protected array  $metadatos;  // JSON extra (duracion, resolucion, etc.)

    public function __construct(array $data) {
        $this->id          = $data['id']          ?? 0;
        $this->nombre      = $data['nombre']       ?? '';
        $this->tipo        = $data['tipo']         ?? '';
        $this->ruta        = $data['ruta']         ?? '';
        $this->tamano      = (int)($data['tamano'] ?? 0);
        $this->etiquetas   = $data['etiquetas']    ?? '';
        $this->fechaSubida = $data['fecha_subida'] ?? date('Y-m-d H:i:s');
        $this->compartido  = (bool)($data['compartido'] ?? false);
        $this->metadatos   = json_decode($data['metadatos'] ?? '{}', true) ?? [];
    }

    // Método abstracto: cada subtipo sabe cómo describirse
    abstract public function getDescripcion(): string;

    // Getters
    public function getId(): int        { return $this->id; }
    public function getNombre(): string { return $this->nombre; }
    public function getTipo(): string   { return $this->tipo; }
    public function getRuta(): string   { return $this->ruta; }
    public function getTamano(): int    { return $this->tamano; }
    public function getEtiquetas(): string { return $this->etiquetas; }
    public function getFechaSubida(): string { return $this->fechaSubida; }
    public function isCompartido(): bool { return $this->compartido; }
    public function getMetadatos(): array { return $this->metadatos; }

    public function getTamanoLegible(): string {
        $kb = $this->tamano / 1024;
        if ($kb < 1024) return round($kb, 1) . ' KB';
        $mb = $kb / 1024;
        if ($mb < 1024) return round($mb, 1) . ' MB';
        return round($mb / 1024, 2) . ' GB';
    }

    public function toArray(): array {
        return [
            'id'          => $this->id,
            'nombre'      => $this->nombre,
            'tipo'        => $this->tipo,
            'ruta'        => $this->ruta,
            'tamano'      => $this->tamano,
            'tamano_legible' => $this->getTamanoLegible(),
            'etiquetas'   => $this->etiquetas,
            'fecha_subida'=> $this->fechaSubida,
            'compartido'  => $this->compartido,
            'metadatos'   => $this->metadatos,
            'descripcion' => $this->getDescripcion(),
        ];
    }
}

// ─── Subtipo: Audio ───────────────────────────────────────────
class Audio extends Archivo {
    public function getDescripcion(): string {
        $dur = $this->metadatos['duracion'] ?? 'desconocida';
        $fmt = $this->metadatos['formato']  ?? 'audio';
        return "🎵 Archivo de audio [{$fmt}] — Duración: {$dur}";
    }
}

// ─── Subtipo: Video ───────────────────────────────────────────
class Video extends Archivo {
    public function getDescripcion(): string {
        $res = $this->metadatos['resolucion'] ?? 'desconocida';
        $dur = $this->metadatos['duracion']   ?? 'desconocida';
        return "🎬 Video [{$res}] — Duración: {$dur}";
    }
}

// ─── Subtipo: Imagen ──────────────────────────────────────────
class Imagen extends Archivo {
    public function getDescripcion(): string {
        $dim = $this->metadatos['dimensiones'] ?? 'desconocidas';
        $fmt = $this->metadatos['formato']     ?? 'imagen';
        return "🖼️ Imagen [{$fmt}] — Dimensiones: {$dim}";
    }
}

/**
 * ============================================================
 * PATRÓN: FACTORY
 * Clase: ArchivoFactory
 * Propósito: Crear el objeto correcto (Audio/Video/Imagen)
 *            sin que el cliente conozca las subclases.
 * ============================================================
 */
class ArchivoFactory {
    // Extensiones reconocidas por tipo
    private static array $mapeoExtensiones = [
        'audio' => ['mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a'],
        'video' => ['mp4', 'avi', 'mov', 'mkv', 'webm', 'wmv'],
        'imagen'=> ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp'],
    ];

    /**
     * Crea un Archivo del subtipo correcto a partir de los datos.
     * Si el tipo no viene explícito, lo infiere por la extensión.
     */
    public static function crear(array $data): Archivo {
        $tipo = strtolower($data['tipo'] ?? '');

        if (empty($tipo)) {
            $tipo = self::inferirTipo($data['nombre'] ?? '');
        }

        return match($tipo) {
            'audio'  => new Audio($data),
            'video'  => new Video($data),
            'imagen' => new Imagen($data),
            default  => throw new InvalidArgumentException(
                "Tipo de archivo no soportado: '{$tipo}'"
            ),
        };
    }

    private static function inferirTipo(string $nombre): string {
        $ext = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
        foreach (self::$mapeoExtensiones as $tipo => $extensiones) {
            if (in_array($ext, $extensiones, true)) {
                return $tipo;
            }
        }
        throw new InvalidArgumentException("Extensión no reconocida: '.{$ext}'");
    }

    public static function getTiposPorExtension(): array {
        return self::$mapeoExtensiones;
    }
}
