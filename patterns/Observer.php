<?php
/**
 * ============================================================
 * PATRÓN: OBSERVER
 * Propósito: Notificar automáticamente a múltiples componentes
 *            cuando ocurre un evento (ej: subida completada).
 *
 * Participantes:
 *   - IObservador        → interfaz que deben implementar los listeners
 *   - NotificadorSubida  → sujeto/emisor que gestiona la lista de observadores
 *   - LoggerObservador   → registra eventos en un log
 *   - EstadisticasObservador → actualiza contadores en BD
 *   - EmailObservador    → simula envío de email al propietario
 * ============================================================
 */

// ─── Interfaz de Observador ───────────────────────────────────
interface IObservador {
    public function actualizar(string $evento, array $datos): void;
}

// ─── Interfaz de Sujeto (Observable) ─────────────────────────
interface ISujeto {
    public function suscribir(IObservador $obs): void;
    public function desuscribir(IObservador $obs): void;
    public function notificar(string $evento, array $datos): void;
}

// ─── Sujeto concreto: NotificadorSubida ──────────────────────
class NotificadorSubida implements ISujeto {
    private array $observadores = [];

    public function suscribir(IObservador $obs): void {
        $this->observadores[spl_object_id($obs)] = $obs;
    }

    public function desuscribir(IObservador $obs): void {
        unset($this->observadores[spl_object_id($obs)]);
    }

    public function notificar(string $evento, array $datos): void {
        foreach ($this->observadores as $obs) {
            $obs->actualizar($evento, $datos);
        }
    }

    // Disparadores de eventos semánticos
    public function archivoSubido(array $archivo): void {
        $this->notificar('archivo_subido', $archivo);
    }

    public function archivoEliminado(array $archivo): void {
        $this->notificar('archivo_eliminado', $archivo);
    }

    public function archivoCompartido(int $id, string $destino): void {
        $this->notificar('archivo_compartido', ['id' => $id, 'destino' => $destino]);
    }
}

// ─── Observador concreto 1: Logger ───────────────────────────
class LoggerObservador implements IObservador {
    private string $archivoLog;

    public function __construct(string $archivoLog = __DIR__ . '/../logs/media.log') {
        $this->archivoLog = $archivoLog;
        // Crear directorio de logs si no existe
        $dir = dirname($archivoLog);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    public function actualizar(string $evento, array $datos): void {
        $linea = sprintf(
            "[%s] EVENTO: %-25s | Datos: %s\n",
            date('Y-m-d H:i:s'),
            strtoupper($evento),
            json_encode($datos, JSON_UNESCAPED_UNICODE)
        );
        file_put_contents($this->archivoLog, $linea, FILE_APPEND | LOCK_EX);
    }
}

// ─── Observador concreto 2: Estadísticas ─────────────────────
class EstadisticasObservador implements IObservador {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function actualizar(string $evento, array $datos): void {
        try {
            match($evento) {
                'archivo_subido'    => $this->incrementarContador($datos['tipo'] ?? 'desconocido', $datos['tamano'] ?? 0),
                'archivo_eliminado' => $this->decrementarContador($datos['tipo'] ?? 'desconocido', $datos['tamano'] ?? 0),
                default             => null,
            };
        } catch (\Exception $e) {
            // No romper el flujo principal si las estadísticas fallan
            error_log("EstadisticasObservador error: " . $e->getMessage());
        }
    }

    private function incrementarContador(string $tipo, int $tamano): void {
        $sql = "INSERT INTO estadisticas (tipo, total_archivos, total_bytes)
                VALUES (:tipo, 1, :bytes)
                ON DUPLICATE KEY UPDATE
                    total_archivos = total_archivos + 1,
                    total_bytes    = total_bytes + :bytes2";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':tipo' => $tipo, ':bytes' => $tamano, ':bytes2' => $tamano]);
    }

    private function decrementarContador(string $tipo, int $tamano): void {
        $sql = "UPDATE estadisticas
                SET total_archivos = GREATEST(total_archivos - 1, 0),
                    total_bytes    = GREATEST(total_bytes - :bytes, 0)
                WHERE tipo = :tipo";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':tipo' => $tipo, ':bytes' => $tamano]);
    }
}

// ─── Observador concreto 3: Notificación email (simulado) ─────
class EmailObservador implements IObservador {
    private string $destinatario;

    public function __construct(string $destinatario) {
        $this->destinatario = $destinatario;
    }

    public function actualizar(string $evento, array $datos): void {
        if ($evento === 'archivo_subido') {
            // En producción: mail() o librería PHPMailer
            $mensaje = "Archivo '{$datos['nombre']}' subido exitosamente a mediaXm.";
            error_log("[EMAIL → {$this->destinatario}] {$mensaje}");
        }
        if ($evento === 'archivo_compartido') {
            $mensaje = "Tu archivo fue compartido con {$datos['destino']}.";
            error_log("[EMAIL → {$this->destinatario}] {$mensaje}");
        }
    }
}
