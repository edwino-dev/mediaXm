<?php
/**
 * ============================================================
 * PATRÓN: SINGLETON
 * Clase: MediaManager
 * Propósito: Gestor principal del sistema. Existe UNA SOLA
 *            instancia que orquesta todos los demás componentes.
 * ============================================================
 */

require_once __DIR__ . '/config/Database.php';
require_once __DIR__ . '/models/Archivo.php';
require_once __DIR__ . '/patterns/Observer.php';
require_once __DIR__ . '/patterns/Strategy.php';
require_once __DIR__ . '/patterns/Decorator.php';
require_once __DIR__ . '/patterns/Adapter.php';

class MediaManager {
    private static ?MediaManager $instancia = null;

    private PDO                $db;
    private NotificadorSubida  $notificador;
    private ?ICloudStorage     $cloudStorage = null;
    private string             $dirSubidas;

    private function __construct() {
        $this->db          = Database::getInstancia()->getConexion();
        $this->dirSubidas  = __DIR__ . '/uploads/';
        $this->notificador = new NotificadorSubida();

        // Registrar observadores por defecto
        $this->notificador->suscribir(new LoggerObservador());
        $this->notificador->suscribir(new EstadisticasObservador($this->db));

        // Crear directorio de subidas si no existe
        if (!is_dir($this->dirSubidas)) {
            mkdir($this->dirSubidas, 0755, true);
        }
    }

    public static function getInstancia(): MediaManager {
        if (self::$instancia === null) {
            self::$instancia = new self();
        }
        return self::$instancia;
    }

    // ─── Configurar Cloud Storage (Adapter) ─────────────────
    public function configurarCloud(ICloudStorage $storage): void {
        $this->cloudStorage = $storage;
    }

    // ─── Registrar observadores externos ────────────────────
    public function suscribirObservador(IObservador $obs): void {
        $this->notificador->suscribir($obs);
    }

    // ─── SUBIR ARCHIVO ──────────────────────────────────────
    public function subirArchivo(array $fileData, array $extras = []): array {
        // 1. Validar
        if (!isset($fileData['tmp_name']) || !is_uploaded_file($fileData['tmp_name'])) {
            throw new RuntimeException("Archivo no válido.");
        }

        $nombre    = basename($fileData['name']);
        $tamano    = $fileData['size'];
        $ext       = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
        $nombreUnico = uniqid('media_', true) . '.' . $ext;
        $rutaLocal   = $this->dirSubidas . $nombreUnico;

        // 2. Mover al directorio de subidas
        if (!move_uploaded_file($fileData['tmp_name'], $rutaLocal)) {
            throw new RuntimeException("Error al guardar el archivo.");
        }

        // 3. Usar Factory para construir el objeto correcto
        $tipo    = ArchivoFactory::crear(['nombre' => $nombre, 'tipo' => ''])->getTipo();
        $archivo = ArchivoFactory::crear([
            'nombre'      => $nombre,
            'tipo'        => $tipo,
            'ruta'        => $nombreUnico,
            'tamano'      => $tamano,
            'etiquetas'   => $extras['etiquetas'] ?? '',
            'fecha_subida'=> date('Y-m-d H:i:s'),
            'compartido'  => 0,
            'metadatos'   => json_encode($extras['metadatos'] ?? []),
        ]);

        // 4. Aplicar Decorators si se especificaron
        if (!empty($extras['decoradores'])) {
            $archivo = DecoradorBuilder::aplicar($archivo, $extras['decoradores'], $extras);
        }

        // 5. Persistir en BD
        $id = $this->persistirArchivo($archivo->toArray(), $nombreUnico);

        // 6. Subir a cloud si está configurado (Adapter)
        $cloudId = null;
        if ($this->cloudStorage) {
            $cloudId = $this->cloudStorage->subir($rutaLocal, $nombre);
            $this->actualizarCloudId($id, $cloudId);
        }

        // 7. Notificar observadores (Observer)
        $data = $archivo->toArray();
        $data['id']       = $id;
        $data['cloud_id'] = $cloudId;
        $this->notificador->archivoSubido($data);

        return $data;
    }

    // ─── BUSCAR ARCHIVOS (Strategy) ────────────────────────
    public function buscar(string $modo, array $criterios): array {
        $estrategia = BuscadorMedia::resolverEstrategia($modo);
        $buscador   = new BuscadorMedia($this->db, $estrategia);
        return $buscador->buscar($criterios);
    }

    // ─── LISTAR TODOS ───────────────────────────────────────
    public function listarTodos(string $tipo = '', int $limite = 50): array {
        if ($tipo) {
            $stmt = $this->db->prepare(
                "SELECT * FROM archivos WHERE tipo = :tipo ORDER BY fecha_subida DESC LIMIT :limite"
            );
            $stmt->execute([':tipo' => $tipo, ':limite' => $limite]);
        } else {
            $stmt = $this->db->prepare(
                "SELECT * FROM archivos ORDER BY fecha_subida DESC LIMIT :limite"
            );
            $stmt->execute([':limite' => $limite]);
        }
        return $stmt->fetchAll();
    }

    // ─── OBTENER POR ID ─────────────────────────────────────
    public function obtenerPorId(int $id): ?Archivo {
        $stmt = $this->db->prepare("SELECT * FROM archivos WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!$row) return null;
        return ArchivoFactory::crear($row);
    }

    // ─── ELIMINAR ARCHIVO ───────────────────────────────────
    public function eliminarArchivo(int $id): bool {
        $archivo = $this->obtenerPorId($id);
        if (!$archivo) return false;

        // Eliminar del sistema de archivos
        $ruta = $this->dirSubidas . $archivo->getRuta();
        if (is_file($ruta)) unlink($ruta);

        // Eliminar de cloud si aplica
        if ($this->cloudStorage) {
            $stmt = $this->db->prepare("SELECT cloud_id FROM archivos WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $cloudId = $stmt->fetchColumn();
            if ($cloudId) $this->cloudStorage->eliminar($cloudId);
        }

        // Eliminar de BD
        $stmt = $this->db->prepare("DELETE FROM archivos WHERE id = :id");
        $stmt->execute([':id' => $id]);

        // Notificar (Observer)
        $this->notificador->archivoEliminado($archivo->toArray());

        return true;
    }

    // ─── COMPARTIR ARCHIVO ──────────────────────────────────
    public function compartirArchivo(int $id, string $destinatario = ''): string {
        $stmt = $this->db->prepare("UPDATE archivos SET compartido = 1 WHERE id = :id");
        $stmt->execute([':id' => $id]);

        $enlace = '';
        if ($this->cloudStorage) {
            $stmt = $this->db->prepare("SELECT cloud_id FROM archivos WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $cloudId = $stmt->fetchColumn();
            if ($cloudId) $enlace = $this->cloudStorage->obtenerEnlace($cloudId);
        }

        // Notificar (Observer)
        $this->notificador->archivoCompartido($id, $destinatario);

        return $enlace ?: "http://mediaxm.local/ver/{$id}";
    }

    // ─── ESTADÍSTICAS ───────────────────────────────────────
    public function obtenerEstadisticas(): array {
        $stmt = $this->db->query(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN tipo='audio'  THEN 1 ELSE 0 END) AS audios,
                SUM(CASE WHEN tipo='video'  THEN 1 ELSE 0 END) AS videos,
                SUM(CASE WHEN tipo='imagen' THEN 1 ELSE 0 END) AS imagenes,
                SUM(tamano) AS bytes_totales
             FROM archivos"
        );
        return $stmt->fetch() ?: ['total'=>0,'audios'=>0,'videos'=>0,'imagenes'=>0,'bytes_totales'=>0];
    }

    // ─── HELPERS PRIVADOS ───────────────────────────────────
    private function persistirArchivo(array $data, string $nombreUnico): int {
        $sql = "INSERT INTO archivos
                    (nombre, tipo, ruta, tamano, etiquetas, fecha_subida, compartido, metadatos)
                VALUES
                    (:nombre, :tipo, :ruta, :tamano, :etiquetas, :fecha_subida, :compartido, :metadatos)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':nombre'      => $data['nombre'],
            ':tipo'        => $data['tipo'],
            ':ruta'        => $nombreUnico,
            ':tamano'      => $data['tamano'],
            ':etiquetas'   => $data['etiquetas'],
            ':fecha_subida'=> $data['fecha_subida'],
            ':compartido'  => (int)$data['compartido'],
            ':metadatos'   => json_encode($data['metadatos'] ?? []),
        ]);
        return (int)$this->db->lastInsertId();
    }

    private function actualizarCloudId(int $id, string $cloudId): void {
        $stmt = $this->db->prepare("UPDATE archivos SET cloud_id = :cloud_id WHERE id = :id");
        $stmt->execute([':cloud_id' => $cloudId, ':id' => $id]);
    }

    private function __clone() {}
    public function __wakeup() {
        throw new \Exception("No se puede deserializar MediaManager.");
    }
}
