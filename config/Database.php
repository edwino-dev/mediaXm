<?php
/**
 * ============================================================
 * PATRÓN: SINGLETON
 * Clase: Database
 * Propósito: Garantizar UNA sola conexión a MySQL en toda la app.
 * ============================================================
 */
class Database {
    private static ?Database $instancia = null;
    private PDO $conexion;

    // Constructor privado — nadie puede hacer "new Database()"
    private function __construct() {
        $host   = 'localhost';
        $dbname = 'mediaxm_db';
        $user   = 'root';
        $pass   = '';

        try {
            $this->conexion = new PDO(
                "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
                $user,
                $pass,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (PDOException $e) {
            // En producción: registrar el error, no mostrarlo
            die(json_encode(['error' => 'Error de conexión a la base de datos.']));
        }
    }

    // Punto de acceso global — siempre retorna la misma instancia
    public static function getInstancia(): Database {
        if (self::$instancia === null) {
            self::$instancia = new self();
        }
        return self::$instancia;
    }

    // Exponer el PDO para realizar consultas
    public function getConexion(): PDO {
        return $this->conexion;
    }

    // Prevenir clonación y deserialización (protegen el Singleton)
    private function __clone() {}
    public function __wakeup() {
        throw new \Exception("No se puede deserializar un Singleton.");
    }
}
