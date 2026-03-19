<?php
/**
 * ============================================================
 * PATRÓN: DECORATOR
 * Propósito: Agregar características opcionales a un Archivo
 *            en tiempo de ejecución sin modificar sus clases.
 *
 * Decoradores:
 *   - MarcadorDecorator  → agrega marcador de posición
 *   - EfectoDecorator    → agrega efecto visual (sepia, blur, etc.)
 *   - ProteccionDecorator → marca el archivo como protegido
 *   - MiniaturasDecorator → indica que tiene miniaturas generadas
 * ============================================================
 */

// ─── Decorador base abstracto ─────────────────────────────────
// Envuelve un Archivo y delega todos los métodos a él.
// Las subclases sólo sobreescriben lo que necesitan cambiar.
abstract class ArchivoDecorator extends Archivo {
    protected Archivo $archivoEnvuelto;

    public function __construct(Archivo $archivo) {
        // No llamamos al constructor padre con datos crudos;
        // delegamos todo al objeto envuelto.
        $this->archivoEnvuelto = $archivo;

        // Sincronizar propiedades para que los getters hereden correctamente
        $this->id          = $archivo->getId();
        $this->nombre      = $archivo->getNombre();
        $this->tipo        = $archivo->getTipo();
        $this->ruta        = $archivo->getRuta();
        $this->tamano      = $archivo->getTamano();
        $this->etiquetas   = $archivo->getEtiquetas();
        $this->fechaSubida = $archivo->getFechaSubida();
        $this->compartido  = $archivo->isCompartido();
        $this->metadatos   = $archivo->getMetadatos();
    }

    // Delegar descripción al objeto envuelto por defecto
    public function getDescripcion(): string {
        return $this->archivoEnvuelto->getDescripcion();
    }

    public function toArray(): array {
        return $this->archivoEnvuelto->toArray();
    }
}

// ─── Decorador 1: Marcador de posición ────────────────────────
class MarcadorDecorator extends ArchivoDecorator {
    private int $posicionSegundos;

    public function __construct(Archivo $archivo, int $posicionSegundos = 0) {
        parent::__construct($archivo);
        $this->posicionSegundos = $posicionSegundos;
    }

    public function getDescripcion(): string {
        $base = parent::getDescripcion();
        $min  = intdiv($this->posicionSegundos, 60);
        $seg  = $this->posicionSegundos % 60;
        return "{$base} | 📌 Marcador en {$min}:{$seg}";
    }

    public function toArray(): array {
        $data = parent::toArray();
        $data['marcador_segundos'] = $this->posicionSegundos;
        $data['extras'][] = 'marcador';
        return $data;
    }
}

// ─── Decorador 2: Efecto visual ───────────────────────────────
class EfectoDecorator extends ArchivoDecorator {
    private string $efecto; // sepia | blur | grayscale | vintage

    public function __construct(Archivo $archivo, string $efecto = 'sepia') {
        parent::__construct($archivo);
        $this->efecto = $efecto;
    }

    public function getDescripcion(): string {
        $base = parent::getDescripcion();
        return "{$base} | ✨ Efecto aplicado: {$this->efecto}";
    }

    public function toArray(): array {
        $data = parent::toArray();
        $data['efecto'] = $this->efecto;
        $data['extras'][] = 'efecto';
        return $data;
    }
}

// ─── Decorador 3: Protección de archivo ──────────────────────
class ProteccionDecorator extends ArchivoDecorator {
    private string $nivel; // lectura | escritura | total

    public function __construct(Archivo $archivo, string $nivel = 'lectura') {
        parent::__construct($archivo);
        $this->nivel = $nivel;
    }

    public function getDescripcion(): string {
        $base = parent::getDescripcion();
        return "{$base} | 🔒 Protegido ({$this->nivel})";
    }

    public function toArray(): array {
        $data = parent::toArray();
        $data['proteccion'] = $this->nivel;
        $data['extras'][] = 'proteccion';
        return $data;
    }
}

// ─── Decorador 4: Miniaturas generadas ────────────────────────
class MiniaturasDecorator extends ArchivoDecorator {
    private array $rutas;

    public function __construct(Archivo $archivo, array $rutas = []) {
        parent::__construct($archivo);
        $this->rutas = $rutas;
    }

    public function getDescripcion(): string {
        $base  = parent::getDescripcion();
        $total = count($this->rutas);
        return "{$base} | 🖼️ {$total} miniatura(s) generada(s)";
    }

    public function toArray(): array {
        $data = parent::toArray();
        $data['miniaturas'] = $this->rutas;
        $data['extras'][] = 'miniaturas';
        return $data;
    }
}

/**
 * ─── Factoría de Decoradores ────────────────────────────────
 * Aplica los decoradores indicados en cadena.
 * Uso: $archivoDeco = DecoradorBuilder::aplicar($archivo, ['marcador','efecto']);
 */
class DecoradorBuilder {
    public static function aplicar(Archivo $archivo, array $extras, array $opciones = []): Archivo {
        foreach ($extras as $extra) {
            $archivo = match($extra) {
                'marcador'   => new MarcadorDecorator($archivo, $opciones['segundos'] ?? 0),
                'efecto'     => new EfectoDecorator($archivo,   $opciones['efecto']   ?? 'sepia'),
                'proteccion' => new ProteccionDecorator($archivo, $opciones['nivel']   ?? 'lectura'),
                'miniaturas' => new MiniaturasDecorator($archivo, $opciones['rutas']   ?? []),
                default      => $archivo,
            };
        }
        return $archivo;
    }
}
