<?php
require_once "auth.php";
require_once "conexion.php";
requerir_login();
session_write_close();

$id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT);
if (!$id) {
    http_response_code(404);
    exit();
}

$st = $conexion->prepare("SELECT Tipo, Contenido FROM imagenes_productos WHERE Id_producto = ?");
$st->execute([$id]);
$img = $st->fetch();

if (!$img) {
    http_response_code(404);
    exit();
}

header('Content-Type: ' . $img['Tipo']);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=2592000');
header('Content-Length: ' . strlen($img['Contenido']));
echo $img['Contenido'];
