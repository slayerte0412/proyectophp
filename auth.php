<?php
date_default_timezone_set('America/La_Paz');

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

define("ROL_ADMIN", 1);
define("ROL_VENDEDOR", 2);

function e($texto)
{
    return htmlspecialchars((string)$texto, ENT_QUOTES, 'UTF-8');
}

function imagen_src($id_producto, $fecha_imagen)
{
    if ($fecha_imagen === null || $fecha_imagen === '') {
        return '';
    }
    return 'imagen.php?id=' . (int)$id_producto . '&v=' . strtotime($fecha_imagen);
}

function requerir_login()
{
    global $conexion;

    if (!isset($_SESSION['Id_usuario'])) {
        header("Location: login.php");
        exit();
    }

    $st = $conexion->prepare(
        "SELECT u.Activo, u.Id_rol, r.Nombre_rol
         FROM usuarios u
         JOIN roles r ON r.Id_rol = u.Id_rol
         WHERE u.Id_usuario = ?"
    );
    $st->execute([$_SESSION['Id_usuario']]);
    $u = $st->fetch();

    if (!$u || (int)$u['Activo'] !== 1) {
        session_unset();
        session_destroy();
        header("Location: login.php");
        exit();
    }

    $_SESSION['Id_rol'] = (int)$u['Id_rol'];
    $_SESSION['Nombre_rol'] = $u['Nombre_rol'];
}

function es_admin()
{
    return isset($_SESSION['Id_rol']) && (int)$_SESSION['Id_rol'] === ROL_ADMIN;
}

function requerir_admin()
{
    requerir_login();
    if (!es_admin()) {
        flash('error', 'No tiene permisos para realizar esta accion.');
        header("Location: panel.php");
        exit();
    }
}

function csrf_token()
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_campo()
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_validar()
{
    $enviado = $_POST['csrf'] ?? '';
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string)$enviado)) {
        http_response_code(403);
        die("Solicitud no valida. Regrese, recargue la pagina e intente de nuevo.");
    }
}

function flash($tipo, $mensaje)
{
    $_SESSION['flash'] = ['tipo' => $tipo, 'msg' => $mensaje];
}

function mostrar_flash()
{
    if (isset($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        $clase = $f['tipo'] === 'exito' ? 'alerta-exito' : 'alerta-error';
        echo '<div class="alerta ' . $clase . '">' . e($f['msg']) . '</div>';
    }
}
