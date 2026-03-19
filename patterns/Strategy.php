<?php
/**
 * ============================================================
 * PATRÓN: STRATEGY
 * Propósito: Encapsular distintos algoritmos de búsqueda e
 *            intercambiarlos en tiempo de ejecución sin cambiar
 *            el código del contexto (BuscadorMedia).
 *
 * Estrategias:
 *   - BusquedaPorNombre   → filtra por substring en el nombre
 *   - BusquedaPorFecha    → filtra por rango de fechas
 *   - BusquedaPorEtiquetas → filtra por etiquetas CSV
 *   - BusquedaPorTipo     → filtra por tipo (audio/video/imagen)
 * ============================================================
 */

// ─── Interfaz de Estrategia ───────────────────────────────────
interface IEstrategiaBusqueda {
    /**
     * Retorna la cláusula WHERE y los parámetros para PDO.
     * @param  array $criterios  Datos del formulario de búsqueda
     * @return array ['where' => string, 'params' => array]
     */
    public function construirConsulta(array $criterios): array;
}

// ─── Estrategia 1: Búsqueda por Nombre ───────────────────────
class BusquedaPorNombre implements IEstrategiaBusqueda {
    public function construirConsulta(array $criterios): array {
        $termino = '%' . ($criterios['q'] ?? '') . '%';
        return [
            'where'  => 'nombre LIKE :termino',
            'params' => [':termino' => $termino],
        ];
    }
}

// ─── Estrategia 2: Búsqueda por Fecha ────────────────────────
class BusquedaPorFecha implements IEstrategiaBusqueda {
    public function construirConsulta(array $criterios): array {
        $desde = $criterios['desde'] ?? date('Y-01-01');
        $hasta = $criterios['hasta'] ?? date('Y-m-d');

        return [
            'where'  => 'DATE(fecha_subida) BETWEEN :desde AND :hasta',
            'params' => [':desde' => $desde, ':hasta' => $hasta],
        ];
    }
}

// ─── Estrategia 3: Búsqueda por Etiquetas ────────────────────
class BusquedaPorEtiquetas implements IEstrategiaBusqueda {
    public function construirConsulta(array $criterios): array {
        $etiqueta = '%' . ($criterios['etiqueta'] ?? '') . '%';
        return [
            'where'  => 'etiquetas LIKE :etiqueta',
            'params' => [':etiqueta' => $etiqueta],
        ];
    }
}

// ─── Estrategia 4: Búsqueda por Tipo ─────────────────────────
class BusquedaPorTipo implements IEstrategiaBusqueda {
    public function construirConsulta(array $criterios): array {
        $tipo = $criterios['tipo'] ?? 'audio';
        return [
            'where'  => 'tipo = :tipo',
            'params' => [':tipo' => $tipo],
        ];
    }
}

/**
 * ─── Contexto: BuscadorMedia ─────────────────────────────────
 * Usa la estrategia inyectada para ejecutar la búsqueda en BD.
 * Cambiar la estrategia es simplemente llamar setEstrategia().
 */
class BuscadorMedia {
    private IEstrategiaBusqueda $estrategia;
    private PDO $db;

    public function __construct(PDO $db, IEstrategiaBusqueda $estrategia) {
        $this->db         = $db;
        $this->estrategia = $estrategia;
    }

    public function setEstrategia(IEstrategiaBusqueda $estrategia): void {
        $this->estrategia = $estrategia;
    }

    public function buscar(array $criterios): array {
        $resultado = $this->estrategia->construirConsulta($criterios);
        $where     = $resultado['where'];
        $params    = $resultado['params'];

        $sql  = "SELECT * FROM archivos WHERE {$where} ORDER BY fecha_subida DESC LIMIT 50";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Fábrica estática: devuelve la estrategia correcta
     * a partir del nombre recibido por $_GET/$_POST.
     */
    public static function resolverEstrategia(string $modo): IEstrategiaBusqueda {
        return match($modo) {
            'fecha'    => new BusquedaPorFecha(),
            'etiqueta' => new BusquedaPorEtiquetas(),
            'tipo'     => new BusquedaPorTipo(),
            default    => new BusquedaPorNombre(),  // "nombre" es el default
        };
    }
}
