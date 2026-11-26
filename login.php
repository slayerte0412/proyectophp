<?php
require_once "auth.php";
require_once "conexion.php";

if (isset($_SESSION['Id_usuario'])) {
    header("Location: panel.php");
    exit();
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    csrf_validar();

    $correo = trim($_POST['correo'] ?? '');
    $contrasena = $_POST['contrasena'] ?? '';

    if ($correo !== '' && $contrasena !== '') {
        $stmt = $conexion->prepare(
            "SELECT u.Id_usuario, u.Nombre_completo, u.Contrasena_hash, u.Id_rol, r.Nombre_rol
             FROM usuarios u
             JOIN roles r ON u.Id_rol = r.Id_rol
             WHERE u.Correo = ? AND u.Activo = 1"
        );
        $stmt->execute([$correo]);
        $usuario = $stmt->fetch();

        if ($usuario && password_verify($contrasena, $usuario['Contrasena_hash'])) {
            session_regenerate_id(true);
            unset($_SESSION['csrf']);
            $_SESSION['Id_usuario'] = $usuario['Id_usuario'];
            $_SESSION['Nombre_completo'] = $usuario['Nombre_completo'];
            $_SESSION['Id_rol'] = $usuario['Id_rol'];
            $_SESSION['Nombre_rol'] = $usuario['Nombre_rol'];

            header("Location: panel.php");
            exit();
        } else {
            usleep(400000);
            $error = "Correo o contraseña incorrectos.";
        }
    } else {
        $error = "Por favor, complete todos los campos.";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar sesión - Lazaroni</title>
    <link rel="stylesheet" href="estilo.css">
</head>
<body class="login-body">

    <div class="login-card">
        <div class="login-cabecera">
            <h1>distribuidora Lazaroni</h1>
            <p>Acceso al Sistema de Gestión de Productos</p>
        </div>

        <div class="login-cuerpo">
            <?php if ($error !== ""): ?>
                <div class="alerta alerta-error"><?php echo e($error); ?></div>
            <?php endif; ?>

            <form method="POST" action="login.php">
                <?php echo csrf_campo(); ?>

                <div class="campo">
                    <label for="correo">Correo electrónico</label>
                    <input type="email" id="correo" name="correo" placeholder="correo@lazaroni.com" value="<?php echo e($_POST['correo'] ?? ''); ?>" required autofocus>
                </div>

                <div class="campo">
                    <label for="contrasena">Contraseña</label>
                    <input type="password" id="contrasena" name="contrasena" placeholder="••••••••" required>
                </div>

                <button type="submit" class="btn btn-primario btn-bloque">Ingresar al sistema</button>
            </form>
        </div>

        <div class="login-pie">
            distribuidora Lazaroni &copy; <?php echo date('Y'); ?>
        </div>
    </div>

</body>
</html>
