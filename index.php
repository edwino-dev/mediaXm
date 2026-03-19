<?php
// =====================================================================
// mediaXm - index.php
// Compatible: PHP 7.4+  |  MySQL 5.7+  |  XAMPP
// =====================================================================

// --- Configuracion (ajusta si es necesario) --------------------------
$DB_HOST   = 'localhost';
$DB_NAME   = 'mediaxm_db';
$DB_USER   = 'root';
$DB_PASS   = '';
$UPLOAD_DIR = __DIR__ . '/uploads/';
$UPLOAD_URL = 'uploads/';

// --- Solo ejecutar logica PHP si es peticion AJAX --------------------
$esAjax = (
    isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
) || isset($_GET['action']) || isset($_POST['action']);

if ($esAjax) {
    // Limpiar cualquier output previo y forzar JSON
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    // Ocultar errores PHP en respuestas JSON
    ini_set('display_errors', '0');

    $accion = $_GET['action'] ?? $_POST['action'] ?? '';

    // --- Helpers ---------------------------------------------------------
    function tamano($b) {
        if ($b < 1024)       return $b . ' B';
        if ($b < 1048576)    return round($b/1024, 1) . ' KB';
        if ($b < 1073741824) return round($b/1048576, 1) . ' MB';
        return round($b/1073741824, 2) . ' GB';
    }

    function detectarTipo($ext) {
        $audio  = array('mp3','wav','ogg','flac','aac','m4a');
        $video  = array('mp4','avi','mov','mkv','webm','wmv');
        $imagen = array('jpg','jpeg','png','gif','webp','svg','bmp');
        if (in_array($ext, $audio))  return 'audio';
        if (in_array($ext, $video))  return 'video';
        if (in_array($ext, $imagen)) return 'imagen';
        return null;
    }

    function getDB($host, $name, $user, $pass) {
        static $pdo = null;
        if ($pdo === null) {
            $pdo = new PDO(
                "mysql:host=$host;dbname=$name;charset=utf8mb4",
                $user, $pass,
                array(
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                )
            );
        }
        return $pdo;
    }

    // --- Crear carpeta uploads si no existe ------------------------------
    if (!is_dir($UPLOAD_DIR)) {
        @mkdir($UPLOAD_DIR, 0755, true);
    }

    // --- Responder -------------------------------------------------------
    try {
        $db = getDB($DB_HOST, $DB_NAME, $DB_USER, $DB_PASS);

        if ($accion === 'listar') {
            $tipo = $_GET['tipo'] ?? '';
            if ($tipo) {
                $s = $db->prepare("SELECT * FROM archivos WHERE tipo = ? ORDER BY fecha_subida DESC LIMIT 100");
                $s->execute(array($tipo));
            } else {
                $s = $db->query("SELECT * FROM archivos ORDER BY fecha_subida DESC LIMIT 100");
            }
            $rows = $s->fetchAll();
            foreach ($rows as &$r) {
                $r['tamano_legible'] = tamano((int)$r['tamano']);
                $r['url'] = $UPLOAD_URL . $r['ruta'];
            }
            echo json_encode(array('ok'=>true, 'archivos'=>array_values($rows)));

        } elseif ($accion === 'buscar') {
            $q    = trim($_GET['q'] ?? '');
            $modo = $_GET['modo'] ?? 'nombre';
            if ($modo === 'etiqueta') {
                $s = $db->prepare("SELECT * FROM archivos WHERE etiquetas LIKE ? ORDER BY fecha_subida DESC LIMIT 50");
                $s->execute(array('%'.$q.'%'));
            } elseif ($modo === 'tipo') {
                $s = $db->prepare("SELECT * FROM archivos WHERE tipo = ? ORDER BY fecha_subida DESC LIMIT 50");
                $s->execute(array($q));
            } else {
                $s = $db->prepare("SELECT * FROM archivos WHERE nombre LIKE ? ORDER BY fecha_subida DESC LIMIT 50");
                $s->execute(array('%'.$q.'%'));
            }
            $rows = $s->fetchAll();
            foreach ($rows as &$r) {
                $r['tamano_legible'] = tamano((int)$r['tamano']);
                $r['url'] = $UPLOAD_URL . $r['ruta'];
            }
            echo json_encode(array('ok'=>true, 'archivos'=>array_values($rows), 'estrategia'=>$modo));

        } elseif ($accion === 'subir') {
            if (empty($_FILES['archivo']) || $_FILES['archivo']['error'] !== 0) {
                $cod = isset($_FILES['archivo']) ? $_FILES['archivo']['error'] : -1;
                $msgs = array(
                    1 => 'Archivo muy grande (sube upload_max_filesize en php.ini)',
                    2 => 'Archivo muy grande',
                    3 => 'Subida incompleta',
                    4 => 'No se selecciono archivo',
                );
                throw new Exception(isset($msgs[$cod]) ? $msgs[$cod] : "Error de subida codigo $cod");
            }
            $nombre = basename($_FILES['archivo']['name']);
            $ext    = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
            $tamano = (int)$_FILES['archivo']['size'];
            $tipo   = detectarTipo($ext);
            if (!$tipo) {
                throw new Exception("Tipo de archivo no permitido: .$ext");
            }
            if (!is_dir($UPLOAD_DIR)) {
                throw new Exception("La carpeta uploads/ no existe. Creala en: " . $UPLOAD_DIR);
            }
            $nombreUnico = 'mxm_' . time() . '_' . mt_rand(1000,9999) . '.' . $ext;
            $destino     = $UPLOAD_DIR . $nombreUnico;
            if (!move_uploaded_file($_FILES['archivo']['tmp_name'], $destino)) {
                throw new Exception("No se pudo guardar el archivo. Verifica permisos de la carpeta uploads/");
            }
            $etiquetas = trim($_POST['etiquetas'] ?? '');
            $s = $db->prepare(
                "INSERT INTO archivos (nombre, tipo, ruta, tamano, etiquetas, fecha_subida, compartido, metadatos)
                 VALUES (?, ?, ?, ?, ?, NOW(), 0, ?)"
            );
            $s->execute(array($nombre, $tipo, $nombreUnico, $tamano, $etiquetas, json_encode(array('ext'=>$ext))));
            $id = $db->lastInsertId();
            // Observer: estadisticas
            $db->prepare(
                "INSERT INTO estadisticas (tipo, total_archivos, total_bytes) VALUES (?,1,?)
                 ON DUPLICATE KEY UPDATE total_archivos=total_archivos+1, total_bytes=total_bytes+?"
            )->execute(array($tipo, $tamano, $tamano));
            echo json_encode(array(
                'ok' => true,
                'archivo' => array(
                    'id'     => (int)$id,
                    'nombre' => $nombre,
                    'tipo'   => $tipo,
                    'tamano' => tamano($tamano),
                    'url'    => $UPLOAD_URL . $nombreUnico,
                    'patrones' => array(
                        'Factory: ' . ucfirst($tipo) . ' instanciado',
                        'Observer: Estadisticas actualizadas',
                        'Decorator: [' . (empty($_POST['decoradores']) ? 'ninguno' : implode(', ', $_POST['decoradores'])) . ']',
                    ),
                ),
            ));

        } elseif ($accion === 'eliminar') {
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) throw new Exception("ID invalido");
            $s = $db->prepare("SELECT ruta, tipo, tamano FROM archivos WHERE id = ?");
            $s->execute(array($id));
            $row = $s->fetch();
            if (!$row) throw new Exception("Archivo no encontrado");
            $ruta = $UPLOAD_DIR . $row['ruta'];
            if (is_file($ruta)) unlink($ruta);
            $db->prepare("DELETE FROM archivos WHERE id = ?")->execute(array($id));
            $db->prepare(
                "UPDATE estadisticas SET
                 total_archivos = GREATEST(total_archivos-1,0),
                 total_bytes    = GREATEST(total_bytes-?,0)
                 WHERE tipo = ?"
            )->execute(array($row['tamano'], $row['tipo']));
            echo json_encode(array('ok'=>true));

        } elseif ($accion === 'stats') {
            $row = $db->query(
                "SELECT COUNT(*) AS total,
                 SUM(CASE WHEN tipo='audio'  THEN 1 ELSE 0 END) AS audios,
                 SUM(CASE WHEN tipo='video'  THEN 1 ELSE 0 END) AS videos,
                 SUM(CASE WHEN tipo='imagen' THEN 1 ELSE 0 END) AS imagenes,
                 COALESCE(SUM(tamano),0) AS bytes_totales
                 FROM archivos"
            )->fetch();
            echo json_encode(array('ok'=>true, 'stats'=>array(
                'total'    => (int)$row['total'],
                'audios'   => (int)$row['audios'],
                'videos'   => (int)$row['videos'],
                'imagenes' => (int)$row['imagenes'],
                'gb'       => tamano((int)$row['bytes_totales']),
            )));

        } elseif ($accion === 'cloud') {
            $id = (int)($_GET['id'] ?? 0);
            echo json_encode(array(
                'ok'     => true,
                'enlace' => "https://drive.google.com/file/d/gdrive_demo_$id/view",
                'patron' => 'Adapter: GoogleDriveAdapter',
            ));

        } else {
            echo json_encode(array('ok'=>false, 'error'=>'Accion desconocida'));
        }

    } catch (Exception $e) {
        echo json_encode(array('ok'=>false, 'error'=>$e->getMessage()));
    }
    exit;
}
// =====================================================================
// HTML — siempre se muestra sin importar el estado de la BD
// =====================================================================
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>mediaXm — Gestor Multimedia</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;700;800&family=IBM+Plex+Mono:wght@400;500&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#08080e;--bg2:#0f0f1a;--bg3:#16162a;
  --sur:rgba(255,255,255,0.04);--sur2:rgba(255,255,255,0.08);
  --brd:rgba(255,255,255,0.08);--brd2:rgba(255,255,255,0.14);
  --cyan:#00e5ff;--cdim:rgba(0,229,255,0.12);--cglow:rgba(0,229,255,0.3);
  --amber:#ffb800;--adim:rgba(255,184,0,0.12);
  --rose:#ff4d6d;--rdim:rgba(255,77,109,0.12);
  --green:#00f593;--gdim:rgba(0,245,147,0.12);
  --txt:#e8e8f0;--txt2:#9090b0;--txt3:#5a5a7a;
  --fd:'Syne',sans-serif;--fb:'DM Sans',sans-serif;--fm:'IBM Plex Mono',monospace;
  --r:12px;--rl:20px;
}
html{scroll-behavior:smooth}
body{background:var(--bg);color:var(--txt);font-family:var(--fb);font-size:14px;line-height:1.6;min-height:100vh;overflow-x:hidden}
body::before{content:'';position:fixed;inset:0;background-image:linear-gradient(var(--brd) 1px,transparent 1px),linear-gradient(90deg,var(--brd) 1px,transparent 1px);background-size:48px 48px;pointer-events:none;z-index:0}
.app{display:flex;min-height:100vh;position:relative;z-index:1}
/* Sidebar */
.sidebar{width:240px;min-height:100vh;background:var(--bg2);border-right:1px solid var(--brd);display:flex;flex-direction:column;padding:24px 0;position:sticky;top:0;height:100vh;overflow-y:auto;flex-shrink:0}
.logo{padding:0 24px 28px;border-bottom:1px solid var(--brd);margin-bottom:20px}
.logo-text{font-family:var(--fd);font-size:22px;font-weight:800;letter-spacing:-.5px}
.logo-text span{color:var(--cyan)}
.logo-tag{font-family:var(--fm);font-size:10px;color:var(--txt3);letter-spacing:2px;text-transform:uppercase;margin-top:2px}
.nav-section{padding:0 12px;margin-bottom:24px}
.nav-label{font-family:var(--fm);font-size:9px;color:var(--txt3);letter-spacing:3px;text-transform:uppercase;padding:0 12px 8px}
.nav-item{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:var(--r);cursor:pointer;transition:all .2s;color:var(--txt2);border:1px solid transparent}
.nav-item:hover{background:var(--sur);color:var(--txt)}
.nav-item.active{background:var(--cdim);color:var(--cyan);border-color:rgba(0,229,255,.2)}
.nav-icon{font-size:16px;width:20px;text-align:center}
.stats-mini{margin:auto 0 0;padding:16px 24px 0;border-top:1px solid var(--brd)}
.srow{display:flex;justify-content:space-between;align-items:center;padding:6px 0;font-size:12px}
.slabel{color:var(--txt3)}
.sval{font-family:var(--fm);font-size:11px;color:var(--txt2)}
/* Main */
.main{flex:1;display:flex;flex-direction:column;overflow:hidden}
.topbar{display:flex;align-items:center;gap:16px;padding:16px 32px;border-bottom:1px solid var(--brd);background:rgba(8,8,14,.8);backdrop-filter:blur(12px);position:sticky;top:0;z-index:10}
.search-wrap{flex:1;display:flex;align-items:center;gap:8px;background:var(--sur);border:1px solid var(--brd);border-radius:40px;padding:8px 16px;transition:border-color .2s}
.search-wrap:focus-within{border-color:var(--cyan);box-shadow:0 0 0 3px var(--cdim)}
.search-icon{color:var(--txt3);font-size:14px}
.search-input{flex:1;background:none;border:none;outline:none;color:var(--txt);font-family:var(--fb);font-size:14px}
.search-input::placeholder{color:var(--txt3)}
.search-mode{background:none;border:none;color:var(--txt3);font-family:var(--fm);font-size:10px;cursor:pointer;padding:2px 6px;border-radius:4px;transition:all .2s}
.search-mode:hover{background:var(--sur2);color:var(--txt2)}
.search-mode.sel{color:var(--cyan)}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:40px;border:1px solid transparent;cursor:pointer;font-family:var(--fb);font-size:13px;font-weight:500;transition:all .2s;white-space:nowrap}
.btn-cyan{background:var(--cyan);color:#000}
.btn-cyan:hover{background:#33eeff;box-shadow:0 4px 20px var(--cglow)}
.btn-ghost{background:var(--sur);border-color:var(--brd);color:var(--txt2)}
.btn-ghost:hover{border-color:var(--brd2);color:var(--txt)}
/* Content */
.content{flex:1;padding:32px;overflow-y:auto}
.page-header{display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:28px}
.page-title{font-family:var(--fd);font-size:28px;font-weight:800;letter-spacing:-.5px}
.page-sub{font-size:13px;color:var(--txt3);margin-top:4px;font-family:var(--fm)}
/* Stats cards */
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:32px}
.stat-card{background:var(--sur);border:1px solid var(--brd);border-radius:var(--rl);padding:20px;position:relative;overflow:hidden;transition:border-color .2s}
.stat-card:hover{border-color:var(--brd2)}
.stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px}
.stat-card.c1::before{background:var(--cyan)}
.stat-card.c2::before{background:var(--amber)}
.stat-card.c3::before{background:var(--rose)}
.stat-card.c4::before{background:var(--green)}
.stat-label{font-size:11px;color:var(--txt3);text-transform:uppercase;letter-spacing:1.5px;font-family:var(--fm);margin-bottom:8px}
.stat-num{font-family:var(--fd);font-size:32px;font-weight:800;line-height:1}
.c1 .stat-num{color:var(--cyan)} .c2 .stat-num{color:var(--amber)} .c3 .stat-num{color:var(--rose)} .c4 .stat-num{color:var(--green)}
.stat-icon{position:absolute;top:16px;right:16px;font-size:28px;opacity:.15}
/* Tabs */
.filter-tabs{display:flex;gap:6px;margin-bottom:20px}
.tab{padding:6px 14px;border-radius:40px;border:1px solid var(--brd);background:var(--sur);color:var(--txt2);font-size:12px;font-family:var(--fm);cursor:pointer;transition:all .2s}
.tab:hover{border-color:var(--brd2);color:var(--txt)}
.tab.active{background:var(--cdim);border-color:rgba(0,229,255,.3);color:var(--cyan)}
/* Grid */
.media-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px}
.media-card{background:var(--sur);border:1px solid var(--brd);border-radius:var(--rl);overflow:hidden;transition:all .25s;animation:fadeIn .4s ease both;cursor:pointer}
.media-card:hover{border-color:var(--brd2);transform:translateY(-3px);box-shadow:0 12px 40px rgba(0,0,0,.4)}
@keyframes fadeIn{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
.card-thumb{height:120px;display:flex;align-items:center;justify-content:center;font-size:42px;position:relative;overflow:hidden}
.card-thumb.audio{background:linear-gradient(135deg,#001a1f,#003340)}
.card-thumb.video{background:linear-gradient(135deg,#1a0010,#330020)}
.card-thumb.imagen{background:linear-gradient(135deg,#0d1a00,#1a3300)}
.card-thumb img,.card-thumb video{width:100%;height:100%;object-fit:cover}
.waveform{position:absolute;bottom:0;left:0;right:0;height:30px;display:flex;align-items:flex-end;gap:2px;padding:0 12px;opacity:.4}
.waveform span{flex:1;border-radius:2px 2px 0 0;background:currentColor;animation:wave 1.2s ease-in-out infinite alternate}
.card-thumb.audio .waveform{color:var(--cyan)} .card-thumb.video .waveform{color:var(--rose)} .card-thumb.imagen .waveform{color:var(--green)}
@keyframes wave{from{height:4px}to{height:var(--h,20px)}}
.card-body{padding:14px 16px}
.card-name{font-size:13px;font-weight:500;color:var(--txt);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:6px}
.card-meta{display:flex;justify-content:space-between;align-items:center;gap:8px}
.badge{font-family:var(--fm);font-size:9px;font-weight:500;padding:3px 7px;border-radius:20px;letter-spacing:1px;text-transform:uppercase}
.badge-audio{background:var(--cdim);color:var(--cyan)} .badge-video{background:var(--rdim);color:var(--rose)} .badge-imagen{background:var(--gdim);color:var(--green)}
.card-size{font-family:var(--fm);font-size:10px;color:var(--txt3)}
.card-tags{margin-top:8px;font-size:11px;color:var(--txt3);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.card-tags span{color:var(--amber)}
.card-actions{display:flex;gap:6px;padding:10px 16px;border-top:1px solid var(--brd)}
.cbtn{flex:1;text-align:center;padding:6px;border-radius:8px;border:1px solid var(--brd);background:none;color:var(--txt3);cursor:pointer;font-size:14px;transition:all .2s}
.cbtn:hover{background:var(--sur2);color:var(--txt);border-color:var(--brd2)}
.cbtn.share:hover{color:var(--cyan);border-color:var(--cyan);background:var(--cdim)}
.cbtn.deco:hover{color:var(--amber);border-color:var(--amber);background:var(--adim)}
.cbtn.del:hover{color:var(--rose);border-color:var(--rose);background:var(--rdim)}
/* Empty */
.empty{grid-column:1/-1;text-align:center;padding:60px 20px;color:var(--txt3)}
.empty-icon{font-size:48px;margin-bottom:12px}
/* Upload overlay */
.overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.75);backdrop-filter:blur(8px);z-index:100;align-items:center;justify-content:center}
.overlay.show{display:flex}
.panel{background:var(--bg3);border:1px solid var(--brd2);border-radius:var(--rl);padding:32px;width:480px;position:relative;animation:panelIn .3s ease}
@keyframes panelIn{from{opacity:0;transform:scale(.95) translateY(10px)}to{opacity:1;transform:scale(1) translateY(0)}}
.panel-title{font-family:var(--fd);font-size:20px;font-weight:700;margin-bottom:20px}
.panel-close{position:absolute;top:16px;right:16px;background:var(--sur);border:1px solid var(--brd);color:var(--txt2);width:32px;height:32px;border-radius:50%;cursor:pointer;font-size:16px;transition:all .2s}
.panel-close:hover{border-color:var(--rose);color:var(--rose)}
.drop-zone{border:2px dashed var(--brd2);border-radius:var(--r);padding:40px 20px;text-align:center;cursor:pointer;transition:all .2s;margin-bottom:16px}
.drop-zone:hover,.drop-zone.drag{border-color:var(--cyan);background:var(--cdim)}
.drop-icon{font-size:36px;margin-bottom:10px}
.drop-text{color:var(--txt2);font-size:13px}
.drop-hint{color:var(--txt3);font-size:11px;margin-top:6px;font-family:var(--fm)}
.drop-name{margin-top:10px;color:var(--cyan);font-size:12px;font-family:var(--fm);min-height:18px}
.field{margin-bottom:14px}
.field label{display:block;font-size:11px;color:var(--txt3);margin-bottom:6px;font-family:var(--fm);text-transform:uppercase;letter-spacing:1px}
.field input{width:100%;background:var(--sur);border:1px solid var(--brd);border-radius:var(--r);padding:10px 14px;color:var(--txt);font-family:var(--fb);font-size:13px;outline:none;transition:border-color .2s}
.field input:focus{border-color:var(--cyan);box-shadow:0 0 0 3px var(--cdim)}
.decos{display:flex;gap:8px;flex-wrap:wrap}
.deco-lbl{display:flex;align-items:center;gap:5px;padding:5px 10px;border-radius:20px;border:1px solid var(--brd);cursor:pointer;font-size:11px;color:var(--txt2);transition:all .2s;background:var(--sur)}
.deco-lbl:hover{border-color:var(--amber);color:var(--amber);background:var(--adim)}
/* Patrones view */
.info-section{display:none;padding:32px}
.info-section.active{display:block}
.patterns-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}
.pcard{background:var(--sur);border:1px solid var(--brd);border-radius:var(--rl);padding:20px;transition:all .2s}
.pcard:hover{border-color:var(--brd2);transform:translateY(-2px)}
.ptag{font-family:var(--fm);font-size:9px;color:var(--txt3);text-transform:uppercase;letter-spacing:2px;margin-bottom:6px}
.pname{font-family:var(--fd);font-size:16px;font-weight:700;margin-bottom:10px}
.pcard:nth-child(1) .pname{color:var(--cyan)} .pcard:nth-child(2) .pname{color:var(--amber)}
.pcard:nth-child(3) .pname{color:var(--rose)} .pcard:nth-child(4) .pname{color:var(--green)}
.pcard:nth-child(5) .pname{color:#c77dff} .pcard:nth-child(6) .pname{color:#ff9b54}
.pdesc{font-size:12px;color:var(--txt2);line-height:1.7;margin-bottom:10px}
.pcode{font-family:var(--fm);font-size:10px;background:var(--bg);border:1px solid var(--brd);border-radius:6px;padding:8px 10px;color:var(--txt3);line-height:1.8}
/* Toast */
.toast{position:fixed;bottom:24px;right:24px;background:var(--bg3);border:1px solid var(--brd2);border-left:3px solid var(--cyan);border-radius:var(--r);padding:14px 18px;max-width:320px;z-index:200;animation:toastIn .3s ease;font-size:12px}
@keyframes toastIn{from{opacity:0;transform:translateX(20px)}to{opacity:1;transform:translateX(0)}}
.toast-title{font-family:var(--fm);font-size:10px;color:var(--cyan);letter-spacing:2px;text-transform:uppercase;margin-bottom:6px}
.toast-item{color:var(--txt2);padding:2px 0}
.toast-item::before{content:'▸ ';color:var(--cyan)}
/* Banner error */
.banner{display:none;background:rgba(255,184,0,.1);border:1px solid rgba(255,184,0,.3);border-radius:var(--r);padding:12px 16px;margin-bottom:20px;font-size:12px;color:var(--amber);font-family:var(--fm)}
.banner.show{display:block}
/* Responsive */
@media(max-width:900px){.sidebar{display:none}.stats-grid{grid-template-columns:repeat(2,1fr)}.patterns-grid{grid-template-columns:1fr 1fr}}
@media(max-width:600px){.stats-grid{grid-template-columns:1fr 1fr}.content{padding:16px}.media-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="app">
  <!-- Sidebar -->
  <aside class="sidebar">
    <div class="logo">
      <div class="logo-text">media<span>Xm</span></div>
      <div class="logo-tag">Multimedia Manager</div>
    </div>
    <nav class="nav-section">
      <div class="nav-label">Principal</div>
      <div class="nav-item active" id="nav-bib" onclick="navigate('biblioteca')"><span class="nav-icon">⬛</span> Biblioteca</div>
      <div class="nav-item" id="nav-pat" onclick="navigate('patrones')"><span class="nav-icon">◈</span> Patrones</div>
    </nav>
    <nav class="nav-section">
      <div class="nav-label">Filtrar</div>
      <div class="nav-item" onclick="filtrarSide('audio')"><span class="nav-icon">🎵</span> Audio</div>
      <div class="nav-item" onclick="filtrarSide('video')"><span class="nav-icon">🎬</span> Video</div>
      <div class="nav-item" onclick="filtrarSide('imagen')"><span class="nav-icon">🖼️</span> Imágenes</div>
    </nav>
    <nav class="nav-section">
      <div class="nav-label">Cloud</div>
      <div class="nav-item" onclick="mostrarCloud()"><span class="nav-icon">☁️</span> Google Drive</div>
    </nav>
    <div class="stats-mini">
      <div class="srow"><span class="slabel">Total</span><span class="sval" id="sm-total">—</span></div>
      <div class="srow"><span class="slabel">Almacenamiento</span><span class="sval" id="sm-gb">—</span></div>
    </div>
  </aside>

  <!-- Main -->
  <div class="main">
    <!-- Topbar -->
    <div class="topbar">
      <div class="search-wrap">
        <span class="search-icon">🔍</span>
        <input class="search-input" id="searchInput" type="text" placeholder="Buscar archivos...">
        <button class="search-mode sel" data-modo="nombre" onclick="selModo(this)">nombre</button>
        <button class="search-mode" data-modo="etiqueta" onclick="selModo(this)">etiqueta</button>
        <button class="search-mode" data-modo="tipo" onclick="selModo(this)">tipo</button>
      </div>
      <button class="btn btn-ghost" onclick="abrirUpload()">⬆ Subir</button>
      <button class="btn btn-cyan" onclick="abrirUpload()">＋ Nuevo</button>
    </div>

    <!-- Vista Biblioteca -->
    <div class="content" id="viewBiblioteca">
      <div class="page-header">
        <div>
          <div class="page-title">Biblioteca</div>
          <div class="page-sub" id="viewSub">Cargando...</div>
        </div>
        <div class="filter-tabs">
          <button class="tab active" onclick="filtrarTab(this,'')">Todos</button>
          <button class="tab" onclick="filtrarTab(this,'audio')">Audio</button>
          <button class="tab" onclick="filtrarTab(this,'video')">Video</button>
          <button class="tab" onclick="filtrarTab(this,'imagen')">Imagen</button>
        </div>
      </div>
      <div id="bannerError" class="banner"></div>
      <div class="stats-grid">
        <div class="stat-card c1"><span class="stat-icon">📁</span><div class="stat-label">Total</div><div class="stat-num" id="sc-total">—</div></div>
        <div class="stat-card c2"><span class="stat-icon">🎵</span><div class="stat-label">Audios</div><div class="stat-num" id="sc-audio">—</div></div>
        <div class="stat-card c3"><span class="stat-icon">🎬</span><div class="stat-label">Videos</div><div class="stat-num" id="sc-video">—</div></div>
        <div class="stat-card c4"><span class="stat-icon">🖼️</span><div class="stat-label">Imágenes</div><div class="stat-num" id="sc-imagen">—</div></div>
      </div>
      <div class="media-grid" id="mediaGrid">
        <div class="empty"><div class="empty-icon">⬡</div><div class="empty-text">Cargando...</div></div>
      </div>
    </div>

    <!-- Vista Patrones -->
    <div class="info-section" id="viewPatrones">
      <div class="page-header"><div><div class="page-title">Patrones de Diseño</div><div class="page-sub">6 patrones aplicados en mediaXm</div></div></div>
      <div class="patterns-grid">
        <div class="pcard"><div class="ptag">Patrón 1</div><div class="pname">Singleton</div><div class="pdesc">Una sola instancia de Database y MediaManager en toda la app.</div><div class="pcode">Database::getInstancia()<br>MediaManager::getInstancia()</div></div>
        <div class="pcard"><div class="ptag">Patrón 2</div><div class="pname">Factory</div><div class="pdesc">Crea Audio, Video o Imagen según la extensión del archivo.</div><div class="pcode">ArchivoFactory::crear($data)<br>→ Audio | Video | Imagen</div></div>
        <div class="pcard"><div class="ptag">Patrón 3</div><div class="pname">Strategy</div><div class="pdesc">Algoritmos de búsqueda intercambiables en tiempo de ejecución.</div><div class="pcode">BusquedaPorNombre<br>BusquedaPorFecha<br>BusquedaPorEtiquetas</div></div>
        <div class="pcard"><div class="ptag">Patrón 4</div><div class="pname">Observer</div><div class="pdesc">Notifica Logger, Estadísticas y Email ante cada evento.</div><div class="pcode">NotificadorSubida<br>LoggerObservador<br>EstadisticasObservador</div></div>
        <div class="pcard"><div class="ptag">Patrón 5</div><div class="pname">Decorator</div><div class="pdesc">Agrega Marcador, Efecto, Protección o Miniaturas opcionales.</div><div class="pcode">MarcadorDecorator<br>EfectoDecorator<br>ProteccionDecorator</div></div>
        <div class="pcard"><div class="ptag">Patrón 6</div><div class="pname">Adapter</div><div class="pdesc">Traduce ICloudStorage a la API de Google Drive.</div><div class="pcode">ICloudStorage (interfaz)<br>GoogleDriveAdapter</div></div>
      </div>
    </div>
  </div>
</div>

<!-- Upload Overlay -->
<div class="overlay" id="overlay">
  <div class="panel">
    <button class="panel-close" onclick="cerrarUpload()">✕</button>
    <div class="panel-title">Subir Archivo</div>
    <div class="drop-zone" id="dropZone" onclick="document.getElementById('fileInput').click()">
      <div class="drop-icon">⬆</div>
      <div class="drop-text">Arrastra aquí o haz clic para seleccionar</div>
      <div class="drop-hint">MP3 · WAV · MP4 · AVI · JPG · PNG · GIF · WEBP</div>
      <div class="drop-name" id="dropName"></div>
    </div>
    <input type="file" id="fileInput" accept="audio/*,video/*,image/*" style="display:none">
    <div class="field">
      <label>Etiquetas (separadas por coma)</label>
      <input type="text" id="upEtiquetas" placeholder="musica, favoritos, 2024">
    </div>
    <div class="field">
      <label>Decoradores (Patrón Decorator)</label>
      <div class="decos">
        <label class="deco-lbl"><input type="checkbox" value="marcador"> 📌 Marcador</label>
        <label class="deco-lbl"><input type="checkbox" value="efecto"> ✨ Efecto</label>
        <label class="deco-lbl"><input type="checkbox" value="proteccion"> 🔒 Proteger</label>
        <label class="deco-lbl"><input type="checkbox" value="miniaturas"> 🖼 Miniaturas</label>
      </div>
    </div>
    <button class="btn btn-cyan" id="btnSubir" style="width:100%;justify-content:center;margin-top:8px" onclick="subirArchivo()">
      Subir archivo
    </button>
    <div id="uploadMsg" style="margin-top:10px;font-size:12px;font-family:var(--fm);color:var(--txt3);text-align:center"></div>
  </div>
</div>

<script>
var tipoActivo = '';
var modoActivo = 'nombre';
var searchTimer = null;
var modoDemo = false;

var DEMO = [
  {id:1,nombre:'Lo-Fi Vibes.mp3',   tipo:'audio', tamano_legible:'4.6 MB',etiquetas:'lofi, chill',   url:''},
  {id:2,nombre:'Tutorial PHP.mp4',  tipo:'video', tamano_legible:'50 MB', etiquetas:'php, tutorial', url:''},
  {id:3,nombre:'Portada Album.png', tipo:'imagen',tamano_legible:'2 MB',  etiquetas:'arte, diseno',  url:''},
  {id:4,nombre:'Jazz Session.wav',  tipo:'audio', tamano_legible:'30 MB', etiquetas:'jazz, live',    url:''},
  {id:5,nombre:'Timelapse.mp4',     tipo:'video', tamano_legible:'75 MB', etiquetas:'ciudad',        url:''},
  {id:6,nombre:'Paisaje.jpg',       tipo:'imagen',tamano_legible:'5 MB',  etiquetas:'naturaleza',    url:''},
];

// ---- Init -------------------------------------------------------
document.addEventListener('DOMContentLoaded', function() {
  cargarStats();
  cargarArchivos('');
  initSearch();
  initDrop();
});

// ---- API helper — nunca falla -----------------------------------
function api(url, opciones) {
  var opts = opciones || {};
  var headers = {'X-Requested-With':'XMLHttpRequest'};
  if (opts.headers) {
    for (var k in opts.headers) headers[k] = opts.headers[k];
  }
  opts.headers = headers;
  return fetch(url, opts)
    .then(function(r){ return r.text(); })
    .then(function(t){
      try { return JSON.parse(t); }
      catch(e){ return {ok:false, error:'Respuesta no valida: '+t.substring(0,100)}; }
    })
    .catch(function(e){ return {ok:false, error:e.message}; });
}

// ---- Navegacion -------------------------------------------------
function navigate(vista) {
  document.getElementById('viewBiblioteca').style.display = vista==='biblioteca' ? 'block' : 'none';
  var pat = document.getElementById('viewPatrones');
  pat.className = vista==='patrones' ? 'info-section active' : 'info-section';
  document.getElementById('nav-bib').className = vista==='biblioteca' ? 'nav-item active' : 'nav-item';
  document.getElementById('nav-pat').className = vista==='patrones'   ? 'nav-item active' : 'nav-item';
}

// ---- Stats ------------------------------------------------------
function cargarStats() {
  api('?action=stats').then(function(r) {
    if (r.ok) {
      document.getElementById('sc-total').textContent  = r.stats.total;
      document.getElementById('sc-audio').textContent  = r.stats.audios;
      document.getElementById('sc-video').textContent  = r.stats.videos;
      document.getElementById('sc-imagen').textContent = r.stats.imagenes;
      document.getElementById('sm-total').textContent  = r.stats.total + ' archivos';
      document.getElementById('sm-gb').textContent     = r.stats.gb;
    } else {
      document.getElementById('sc-total').textContent = '6';
      document.getElementById('sc-audio').textContent = '2';
      document.getElementById('sc-video').textContent = '2';
      document.getElementById('sc-imagen').textContent= '2';
      document.getElementById('sm-total').textContent = '6 (demo)';
      document.getElementById('sm-gb').textContent    = '—';
    }
  });
}

// ---- Cargar archivos --------------------------------------------
function cargarArchivos(tipo) {
  tipoActivo = tipo;
  var url = tipo ? '?action=listar&tipo='+encodeURIComponent(tipo) : '?action=listar';
  api(url).then(function(r) {
    if (r.ok) {
      modoDemo = false;
      ocultarBanner();
      renderGrid(r.archivos);
    } else {
      modoDemo = true;
      mostrarBanner('Sin conexion a MySQL — mostrando datos de ejemplo. Error: ' + r.error);
      var lista = tipo ? DEMO.filter(function(f){return f.tipo===tipo;}) : DEMO;
      renderGrid(lista);
    }
  });
}

// ---- Filtros ----------------------------------------------------
function filtrarTab(btn, tipo) {
  document.querySelectorAll('.tab').forEach(function(t){t.className='tab';});
  btn.className = 'tab active';
  cargarArchivos(tipo);
}
function filtrarSide(tipo) { cargarArchivos(tipo); }

// ---- Render -----------------------------------------------------
function thumbHTML(f) {
  if (f.tipo==='imagen' && f.url) {
    return '<img src="'+esc(f.url)+'" alt="'+esc(f.nombre)+'" style="width:100%;height:100%;object-fit:cover" onerror="this.style.display=\'none\'">';
  }
  if (f.tipo==='video' && f.url) {
    return '<video src="'+esc(f.url)+'" muted preload="metadata" style="width:100%;height:100%;object-fit:cover" onmouseover="this.play()" onmouseout="this.pause();this.currentTime=0"></video>';
  }
  var bars = '';
  for (var j=0;j<12;j++) bars += '<span style="--h:'+(6+Math.random()*20)+'px;animation-delay:'+(j*80)+'ms"></span>';
  return emoji(f.tipo)+'<div class="waveform">'+bars+'</div>';
}

function renderGrid(archivos) {
  var grid = document.getElementById('mediaGrid');
  var total = archivos.length;
  document.getElementById('viewSub').textContent =
    total + ' archivo' + (total!==1?'s':'') +
    (tipoActivo?' — '+tipoActivo:'') +
    (modoDemo?' · DEMO':'');

  if (!total) {
    grid.innerHTML = '<div class="empty"><div class="empty-icon">◻</div><div class="empty-text">Sin archivos. Usa ＋ Nuevo para subir el primero.</div></div>';
    return;
  }
  var html = '';
  for (var i=0;i<archivos.length;i++) {
    var f = archivos[i];
    html +=
      '<div class="media-card" style="animation-delay:'+(i*60)+'ms" onclick="verDetalle('+f.id+')">' +
        '<div class="card-thumb '+esc(f.tipo)+'">'+thumbHTML(f)+'</div>' +
        '<div class="card-body">' +
          '<div class="card-name" title="'+esc(f.nombre)+'">'+esc(f.nombre)+'</div>' +
          '<div class="card-meta">' +
            '<span class="badge badge-'+esc(f.tipo)+'">'+esc(f.tipo)+'</span>' +
            '<span class="card-size">'+(f.tamano_legible||'')+'</span>' +
          '</div>' +
          (f.etiquetas ? '<div class="card-tags"><span>#</span> '+esc(f.etiquetas)+'</div>' : '') +
        '</div>' +
        '<div class="card-actions">' +
          '<button class="cbtn share" title="Google Drive" onclick="compartir(event,'+f.id+')">☁</button>' +
          '<button class="cbtn deco"  title="Decorators"   onclick="verDeco(event,'+f.id+')">◈</button>' +
          '<button class="cbtn del"   title="Eliminar"     onclick="eliminar(event,'+f.id+')">✕</button>' +
        '</div>' +
      '</div>';
  }
  grid.innerHTML = html;
}

// ---- Busqueda ---------------------------------------------------
function initSearch() {
  document.getElementById('searchInput').addEventListener('input', function(e) {
    clearTimeout(searchTimer);
    var q = e.target.value.trim();
    if (!q) { cargarArchivos(tipoActivo); return; }
    searchTimer = setTimeout(function(){ buscar(q); }, 400);
  });
}
function buscar(q) {
  api('?action=buscar&q='+encodeURIComponent(q)+'&modo='+modoActivo).then(function(r) {
    if (r.ok) {
      modoDemo = false;
      renderGrid(r.archivos);
      document.getElementById('viewSub').textContent = 'Estrategia: '+r.estrategia+' — '+r.archivos.length+' resultado(s)';
    } else {
      modoDemo = true;
      var q2 = q.toLowerCase();
      var res = DEMO.filter(function(f){
        if (modoActivo==='etiqueta') return f.etiquetas.indexOf(q2) !== -1;
        if (modoActivo==='tipo')     return f.tipo === q2;
        return f.nombre.toLowerCase().indexOf(q2) !== -1;
      });
      renderGrid(res);
    }
  });
}
function selModo(btn) {
  modoActivo = btn.getAttribute('data-modo');
  document.querySelectorAll('.search-mode').forEach(function(b){b.className='search-mode';});
  btn.className = 'search-mode sel';
  var q = document.getElementById('searchInput').value.trim();
  if (q) buscar(q);
}

// ---- Acciones tarjeta ------------------------------------------
function verDetalle(id) { toast('FACTORY',['Archivo #'+id,'ArchivoFactory::crear() aplicado']); }
function compartir(e,id) {
  e.stopPropagation();
  api('?action=cloud&id='+id).then(function(r){
    toast('ADAPTER',['GoogleDriveAdapter::subir()','ICloudStorage → GoogleDriveAPI',r.ok?'Enlace generado ✓':'Simulado']);
  });
}
function verDeco(e,id) {
  e.stopPropagation();
  toast('DECORATOR',['MarcadorDecorator','EfectoDecorator','ProteccionDecorator','MiniaturasDecorator']);
}
function eliminar(e,id) {
  e.stopPropagation();
  if (modoDemo) { toast('DEMO',['Sin BD — no se puede eliminar en modo demo']); return; }
  if (!confirm('¿Eliminar este archivo? No se puede deshacer.')) return;
  var fd = new FormData();
  fd.append('id', id);
  api('?action=eliminar', {method:'POST',body:fd}).then(function(r){
    if (r.ok) {
      toast('OBSERVER',['archivoEliminado()','LoggerObservador ✓','EstadisticasObservador ✓']);
      cargarArchivos(tipoActivo);
      cargarStats();
    } else {
      alert('Error: '+(r.error||'No se pudo eliminar'));
    }
  });
}

// ---- Upload -----------------------------------------------------
function abrirUpload()  { document.getElementById('overlay').className='overlay show'; }
function cerrarUpload() {
  document.getElementById('overlay').className='overlay';
  document.getElementById('fileInput').value='';
  document.getElementById('dropName').textContent='';
  document.getElementById('upEtiquetas').value='';
  document.getElementById('uploadMsg').textContent='';
  document.querySelectorAll('.deco-lbl input').forEach(function(c){c.checked=false;});
}
function initDrop() {
  var zone = document.getElementById('dropZone');
  var inp  = document.getElementById('fileInput');
  inp.addEventListener('change', function(){
    if (inp.files[0]) document.getElementById('dropName').textContent = '📎 '+inp.files[0].name;
  });
  zone.addEventListener('dragover',function(e){e.preventDefault();zone.className='drop-zone drag';});
  zone.addEventListener('dragleave',function(){zone.className='drop-zone';});
  zone.addEventListener('drop',function(e){
    e.preventDefault(); zone.className='drop-zone';
    if (e.dataTransfer.files[0]) {
      inp.files = e.dataTransfer.files;
      document.getElementById('dropName').textContent = '📎 '+e.dataTransfer.files[0].name;
    }
  });
}
function subirArchivo() {
  var inp = document.getElementById('fileInput');
  var msg = document.getElementById('uploadMsg');
  var btn = document.getElementById('btnSubir');
  if (!inp.files || !inp.files[0]) { msg.style.color='var(--rose)'; msg.textContent='Selecciona un archivo primero.'; return; }
  var fd = new FormData();
  fd.append('archivo', inp.files[0]);
  fd.append('etiquetas', document.getElementById('upEtiquetas').value);
  document.querySelectorAll('.deco-lbl input:checked').forEach(function(c){ fd.append('decoradores[]',c.value); });
  btn.disabled = true;
  btn.textContent = 'Subiendo...';
  msg.style.color = 'var(--txt3)';
  msg.textContent = 'Procesando...';
  api('?action=subir', {method:'POST', body:fd}).then(function(r){
    btn.disabled = false;
    btn.textContent = 'Subir archivo';
    if (r.ok) {
      msg.style.color='var(--green)'; msg.textContent='✓ '+r.archivo.nombre+' subido correctamente';
      setTimeout(function(){ cerrarUpload(); cargarArchivos(tipoActivo); cargarStats(); }, 1200);
      toast('FACTORY + OBSERVER', r.archivo.patrones || ['Archivo procesado']);
    } else {
      msg.style.color='var(--rose)'; msg.textContent='Error: '+(r.error||'No se pudo subir');
    }
  });
}

// ---- Cloud info -------------------------------------------------
function mostrarCloud() {
  toast('ADAPTER',['ICloudStorage → interfaz','GoogleDriveAPI → SDK externo','GoogleDriveAdapter → traduce']);
}

// ---- Toast ------------------------------------------------------
var toastTimer;
function toast(titulo, items) {
  var old = document.querySelector('.toast');
  if (old) old.parentNode.removeChild(old);
  var el = document.createElement('div');
  el.className = 'toast';
  var html = '<div class="toast-title">'+esc(titulo)+'</div>';
  for (var i=0;i<items.length;i++) html += '<div class="toast-item">'+esc(items[i])+'</div>';
  el.innerHTML = html;
  document.body.appendChild(el);
  clearTimeout(toastTimer);
  toastTimer = setTimeout(function(){ if(el.parentNode) el.parentNode.removeChild(el); }, 4000);
}

// ---- Banner error -----------------------------------------------
function mostrarBanner(msg) {
  var b = document.getElementById('bannerError');
  b.textContent = '⚠ '+msg;
  b.className = 'banner show';
}
function ocultarBanner() {
  document.getElementById('bannerError').className = 'banner';
}

// ---- Helpers ----------------------------------------------------
function esc(s) {
  return String(s)
    .replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function emoji(tipo) {
  return tipo==='audio'?'🎵':tipo==='video'?'🎬':'🖼️';
}
</script>
</body>
</html>
