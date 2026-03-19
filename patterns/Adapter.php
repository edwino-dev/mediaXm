<?php
/**
 * ============================================================
 * PATRÓN: ADAPTER
 * Propósito: Conectar mediaXm con Google Drive (interfaz externa
 *            incompatible) sin acoplar la app a la API de Google.
 *
 * Participantes:
 *   - ICloudStorage      → interfaz que espera la aplicación
 *   - GoogleDriveAPI     → simula la SDK oficial de Google Drive
 *                          (en producción: google/apiclient)
 *   - GoogleDriveAdapter → adapta GoogleDriveAPI a ICloudStorage
 * ============================================================
 */

// ─── Interfaz que la app conoce y usa ─────────────────────────
interface ICloudStorage {
    public function subir(string $rutaLocal, string $nombreDestino): string;
    public function descargar(string $idRemoto, string $rutaLocal): bool;
    public function eliminar(string $idRemoto): bool;
    public function listar(string $carpeta = ''): array;
    public function obtenerEnlace(string $idRemoto): string;
}

// ─── API simulada de Google Drive ────────────────────────────
// Representa la interfaz real del SDK de Google
// (métodos con nombres y formatos distintos a ICloudStorage).
class GoogleDriveAPI {
    private string $accessToken;
    private string $baseUrl = 'https://www.googleapis.com/drive/v3';

    public function __construct(string $accessToken) {
        $this->accessToken = $accessToken;
    }

    // Sube un archivo; retorna el objeto File de Drive
    public function files_create(array $metadata, string $contenido): array {
        // Simulación: en producción haría un multipart POST a Drive API
        $fakeId = 'gdrive_' . bin2hex(random_bytes(8));
        return [
            'id'       => $fakeId,
            'name'     => $metadata['name'],
            'mimeType' => $metadata['mimeType'] ?? 'application/octet-stream',
            'size'     => strlen($contenido),
            'webViewLink' => "https://drive.google.com/file/d/{$fakeId}/view",
        ];
    }

    // Descarga el contenido binario de un archivo
    public function files_get_media(string $fileId): string|false {
        // Simulación: retorna contenido falso
        return "BINARY_CONTENT_OF_{$fileId}";
    }

    // Elimina un archivo por su ID
    public function files_delete(string $fileId): bool {
        return true; // Simulación
    }

    // Lista archivos de una carpeta
    public function files_list(array $params): array {
        return [
            'files' => [
                ['id' => 'gdrive_abc123', 'name' => 'cancion.mp3',   'mimeType' => 'audio/mpeg',  'size' => 5242880],
                ['id' => 'gdrive_def456', 'name' => 'pelicula.mp4',  'mimeType' => 'video/mp4',   'size' => 104857600],
                ['id' => 'gdrive_ghi789', 'name' => 'foto.jpg',      'mimeType' => 'image/jpeg',  'size' => 2097152],
            ]
        ];
    }

    // Genera un enlace público compartible
    public function permissions_create(string $fileId, array $role): string {
        return "https://drive.google.com/file/d/{$fileId}/view?usp=sharing";
    }
}

// ─── Adapter: Traduce ICloudStorage → GoogleDriveAPI ─────────
class GoogleDriveAdapter implements ICloudStorage {
    private GoogleDriveAPI $driveApi;
    private array $mimeTypes = [
        'mp3'  => 'audio/mpeg',
        'wav'  => 'audio/wav',
        'ogg'  => 'audio/ogg',
        'mp4'  => 'video/mp4',
        'avi'  => 'video/x-msvideo',
        'mov'  => 'video/quicktime',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
    ];

    public function __construct(string $accessToken) {
        $this->driveApi = new GoogleDriveAPI($accessToken);
    }

    /**
     * Adapta: subir(ruta, nombre) → files_create(metadata, contenido)
     */
    public function subir(string $rutaLocal, string $nombreDestino): string {
        $ext      = strtolower(pathinfo($nombreDestino, PATHINFO_EXTENSION));
        $mimeType = $this->mimeTypes[$ext] ?? 'application/octet-stream';

        // Leer contenido real del archivo (en producción con file_get_contents)
        $contenido = is_file($rutaLocal) ? file_get_contents($rutaLocal) : "SIMULATED_CONTENT";

        $resultado = $this->driveApi->files_create(
            ['name' => $nombreDestino, 'mimeType' => $mimeType],
            $contenido
        );

        return $resultado['id']; // Retornar el ID asignado por Drive
    }

    /**
     * Adapta: descargar(idRemoto, rutaLocal) → files_get_media(fileId)
     */
    public function descargar(string $idRemoto, string $rutaLocal): bool {
        $contenido = $this->driveApi->files_get_media($idRemoto);
        if ($contenido === false) return false;
        return file_put_contents($rutaLocal, $contenido) !== false;
    }

    /**
     * Adapta: eliminar(idRemoto) → files_delete(fileId)
     */
    public function eliminar(string $idRemoto): bool {
        return $this->driveApi->files_delete($idRemoto);
    }

    /**
     * Adapta: listar(carpeta) → files_list(params) + normaliza resultado
     */
    public function listar(string $carpeta = ''): array {
        $params = ['q' => "trashed=false"];
        if ($carpeta) {
            $params['q'] .= " and '{$carpeta}' in parents";
        }

        $respuesta = $this->driveApi->files_list($params);

        // Normalizar formato de Drive al formato interno de mediaXm
        return array_map(fn($f) => [
            'id'      => $f['id'],
            'nombre'  => $f['name'],
            'tipo'    => $this->inferirTipo($f['mimeType']),
            'tamano'  => $f['size'] ?? 0,
            'origen'  => 'google_drive',
        ], $respuesta['files'] ?? []);
    }

    /**
     * Adapta: obtenerEnlace(idRemoto) → permissions_create(fileId, role)
     */
    public function obtenerEnlace(string $idRemoto): string {
        return $this->driveApi->permissions_create(
            $idRemoto,
            ['role' => 'reader', 'type' => 'anyone']
        );
    }

    private function inferirTipo(string $mimeType): string {
        if (str_starts_with($mimeType, 'audio/')) return 'audio';
        if (str_starts_with($mimeType, 'video/')) return 'video';
        if (str_starts_with($mimeType, 'image/')) return 'imagen';
        return 'desconocido';
    }
}
