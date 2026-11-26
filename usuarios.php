<?php
require_once "auth.php";
require_once "conexion.php";
requerir_admin();

$roles = $conexion->query("SELECT Id_rol, Nombre_rol FROM roles ORDER BY Id_rol")->fetchAll();
$ids_roles = array_map('intval', array_column($roles, 'Id_rol'));

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    csrf_validar();

    $accion = $_POST['accion'] ?? '';
    $id = filter_var($_POST['id_usuario'] ?? '', FILTER_VALIDATE_INT);
    $nombre = trim($_POST['nombre_completo'] ?? '');
    $correo = trim($_POST['correo'] ?? '');
    $id_rol = filter_var($_POST['id_rol'] ?? '', FILTER_VALIDATE_INT);
    $contrasena = $_POST['contrasena'] ?? '';

    if ($accion === 'estado') {
        $nuevo = ($_POST['nuevo_estado'] ?? '') === '1' ? 1 : 0;
        if (!$id) {
            flash('error', 'Usuario no válido.');
        } elseif ($id === (int)$_SESSION['Id_usuario'] && $nuevo === 0) {
            flash('error', 'No puede desactivar su propia cuenta.');
        } else {
            $st = $conexion->prepare("UPDATE usuarios SET Activo = ? WHERE Id_usuario = ?");
            $st->execute([$nuevo, $id]);
            flash('exito', $nuevo === 1 ? 'Usuario activado correctamente.' : 'Usuario desactivado correctamente.');
        }
    } elseif ($accion === 'crear' || $accion === 'editar') {
        $errores = [];

        if ($nombre === '' || mb_strlen($nombre) > 100) {
            $errores[] = "El nombre es obligatorio (máximo 100 caracteres).";
        }
        if (!filter_var($correo, FILTER_VALIDATE_EMAIL) || mb_strlen($correo) > 100) {
            $errores[] = "Ingrese un correo válido.";
        }
        if (!$id_rol || !in_array($id_rol, $ids_roles, true)) {
            $errores[] = "Seleccione un rol válido.";
        }
        if ($accion === 'crear' && (strlen($contrasena) < 6 || strlen($contrasena) > 60)) {
            $errores[] = "La contraseña debe tener entre 6 y 60 caracteres.";
        }
        if ($accion === 'editar') {
            if (!$id) {
                $errores[] = "Usuario no válido.";
            }
            if ($contrasena !== '' && (strlen($contrasena) < 6 || strlen($contrasena) > 60)) {
                $errores[] = "La nueva contraseña debe tener entre 6 y 60 caracteres.";
            }
            if ($id && $id_rol && $id === (int)$_SESSION['Id_usuario'] && $id_rol !== (int)$_SESSION['Id_rol']) {
                $errores[] = "No puede cambiar su propio rol.";
            }
        }

        if (!empty($errores)) {
            flash('error', implode(' ', $errores));
        } else {
            try {
                if ($accion === 'crear') {
                    $st = $conexion->prepare("SELECT COUNT(*) FROM usuarios WHERE Correo = ?");
                    $st->execute([$correo]);
                    if ((int)$st->fetchColumn() > 0) {
                        flash('error', 'Ya existe un usuario con ese correo.');
                    } else {
                        $st = $conexion->prepare(
                            "INSERT INTO usuarios (Nombre_completo, Correo, Contrasena_hash, Id_rol) VALUES (?, ?, ?, ?)"
                        );
                        $st->execute([$nombre, $correo, password_hash($contrasena, PASSWORD_DEFAULT), $id_rol]);
                        flash('exito', 'Usuario creado correctamente.');
                    }
                } else {
                    $st = $conexion->prepare("SELECT COUNT(*) FROM usuarios WHERE Correo = ? AND Id_usuario <> ?");
                    $st->execute([$correo, $id]);
                    if ((int)$st->fetchColumn() > 0) {
                        flash('error', 'Ya existe otro usuario con ese correo.');
                    } else {
                        if ($contrasena !== '') {
                            $st = $conexion->prepare(
                                "UPDATE usuarios SET Nombre_completo = ?, Correo = ?, Id_rol = ?, Contrasena_hash = ? WHERE Id_usuario = ?"
                            );
                            $st->execute([$nombre, $correo, $id_rol, password_hash($contrasena, PASSWORD_DEFAULT), $id]);
                        } else {
                            $st = $conexion->prepare(
                                "UPDATE usuarios SET Nombre_completo = ?, Correo = ?, Id_rol = ? WHERE Id_usuario = ?"
                            );
                            $st->execute([$nombre, $correo, $id_rol, $id]);
                        }
                        flash('exito', 'Usuario actualizado correctamente.');
                    }
                }
            } catch (Exception $ex) {
                error_log("Error en usuarios.php: " . $ex->getMessage());
                flash('error', 'No se pudo guardar el usuario. Intente nuevamente.');
            }
        }
    }

    header("Location: usuarios.php");
    exit();
}

$q = trim($_GET['q'] ?? '');
$where = "1 = 1";
$params = [];
if ($q !== '') {
    $where .= " AND (u.Nombre_completo LIKE ? OR u.Correo LIKE ?)";
    $like = "%" . $q . "%";
    array_push($params, $like, $like);
}

$st = $conexion->prepare(
    "SELECT u.Id_usuario, u.Nombre_completo, u.Correo, u.Id_rol, u.Activo, u.Fecha_registro, r.Nombre_rol
     FROM usuarios u
     JOIN roles r ON r.Id_rol = u.Id_rol
     WHERE $where
     ORDER BY u.Activo DESC, u.Nombre_completo"
);
$st->execute($params);
$usuarios = $st->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Usuarios - Lazaroni</title>
    <link rel="stylesheet" href="estilo.css">
</head>
<body>

    <?php include "nav.php"; ?>

    <main class="contenedor">
        <h1 class="titulo-pagina">Usuarios</h1>
        <p class="subtitulo">Administre las cuentas de administradores y vendedores</p>

        <?php mostrar_flash(); ?>

        <section class="panel">
            <h2 class="panel-titulo">Listado de usuarios</h2>
            <p class="panel-subtitulo"><?php echo count($usuarios); ?> usuario(s) <?php echo $q !== '' ? 'encontrado(s)' : 'registrado(s)'; ?></p>

            <div class="barra-herramientas">
                <form method="GET" action="usuarios.php" class="buscador">
                    <input type="search" name="q" value="<?php echo e($q); ?>" placeholder="Buscar por nombre o correo">
                    <button type="submit" class="btn btn-secundario">Buscar</button>
                    <?php if ($q !== ''): ?>
                        <a href="usuarios.php" class="btn btn-secundario">Limpiar</a>
                    <?php endif; ?>
                </form>
                <button type="button" class="btn btn-primario" onclick="abrirAgregar()">+ Agregar usuario</button>
            </div>

            <div class="tabla-scroll">
                <table class="tabla">
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Correo</th>
                            <th class="centro">Rol</th>
                            <th class="centro">Estado</th>
                            <th class="centro">Registro</th>
                            <th class="centro">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($usuarios)): ?>
                            <tr><td colspan="6" class="vacio">No se encontraron usuarios.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($usuarios as $u):
                            $es_yo = (int)$u['Id_usuario'] === (int)$_SESSION['Id_usuario'];
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo e($u['Nombre_completo']); ?></strong>
                                <?php if ($es_yo): ?><span class="sub">Su cuenta</span><?php endif; ?>
                            </td>
                            <td><?php echo e($u['Correo']); ?></td>
                            <td class="centro"><?php echo e($u['Nombre_rol']); ?></td>
                            <td class="centro">
                                <?php if ((int)$u['Activo'] === 1): ?>
                                    <span class="badge badge-normal">Activo</span>
                                <?php else: ?>
                                    <span class="badge badge-agotado">Inactivo</span>
                                <?php endif; ?>
                            </td>
                            <td class="centro"><?php echo e(date('d/m/Y', strtotime($u['Fecha_registro']))); ?></td>
                            <td>
                                <div class="acciones">
                                    <button type="button" class="btn btn-secundario btn-chico"
                                        data-id="<?php echo (int)$u['Id_usuario']; ?>"
                                        data-nombre="<?php echo e($u['Nombre_completo']); ?>"
                                        data-correo="<?php echo e($u['Correo']); ?>"
                                        data-rol="<?php echo (int)$u['Id_rol']; ?>"
                                        data-yo="<?php echo $es_yo ? '1' : '0'; ?>"
                                        onclick="abrirEditar(this)">Editar</button>

                                    <?php if (!$es_yo): ?>
                                        <form method="POST" action="usuarios.php" onsubmit="return confirm('¿Cambiar el estado de este usuario?');">
                                            <?php echo csrf_campo(); ?>
                                            <input type="hidden" name="accion" value="estado">
                                            <input type="hidden" name="id_usuario" value="<?php echo (int)$u['Id_usuario']; ?>">
                                            <?php if ((int)$u['Activo'] === 1): ?>
                                                <input type="hidden" name="nuevo_estado" value="0">
                                                <button type="submit" class="btn btn-peligro btn-chico">Desactivar</button>
                                            <?php else: ?>
                                                <input type="hidden" name="nuevo_estado" value="1">
                                                <button type="submit" class="btn btn-exito btn-chico">Activar</button>
                                            <?php endif; ?>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>

    <div class="modal-fondo" id="modal">
        <div class="modal chico">
            <div class="modal-cabecera">
                <h3 id="modal-titulo">Agregar usuario</h3>
                <button type="button" class="modal-cerrar" onclick="cerrarModal()" aria-label="Cerrar">&times;</button>
            </div>
            <div class="modal-cuerpo">
                <form method="POST" action="usuarios.php" id="formulario" autocomplete="off">
                    <?php echo csrf_campo(); ?>
                    <input type="hidden" name="accion" id="accion" value="crear">
                    <input type="hidden" name="id_usuario" id="id_usuario" value="">

                    <div class="form-grid">
                        <div class="campo ancho">
                            <label for="nombre_completo">Nombre completo</label>
                            <input type="text" id="nombre_completo" name="nombre_completo" maxlength="100" required>
                        </div>
                        <div class="campo ancho">
                            <label for="correo">Correo electrónico</label>
                            <input type="email" id="correo" name="correo" maxlength="100" placeholder="usuario@lazaroni.com" required>
                        </div>
                        <div class="campo">
                            <label for="id_rol">Rol</label>
                            <select id="id_rol" name="id_rol" required>
                                <?php foreach ($roles as $r): ?>
                                    <option value="<?php echo (int)$r['Id_rol']; ?>"><?php echo e($r['Nombre_rol']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="campo">
                            <label for="contrasena" id="etiqueta-clave">Contraseña</label>
                            <input type="password" id="contrasena" name="contrasena" maxlength="60" autocomplete="new-password" required>
                            <span class="ayuda" id="ayuda-clave">Mínimo 6 caracteres.</span>
                        </div>
                    </div>

                    <div class="acciones-modal">
                        <button type="button" class="btn btn-secundario" onclick="cerrarModal()">Cancelar</button>
                        <button type="submit" class="btn btn-primario" id="btn-guardar">Guardar usuario</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        const modal = document.getElementById('modal');
        const formulario = document.getElementById('formulario');
        const campoClave = document.getElementById('contrasena');
        const selRol = document.getElementById('id_rol');

        function abrirAgregar() {
            formulario.reset();
            document.getElementById('accion').value = 'crear';
            document.getElementById('id_usuario').value = '';
            document.getElementById('modal-titulo').textContent = 'Agregar usuario';
            document.getElementById('btn-guardar').textContent = 'Guardar usuario';
            document.getElementById('etiqueta-clave').textContent = 'Contraseña';
            document.getElementById('ayuda-clave').textContent = 'Mínimo 6 caracteres.';
            campoClave.required = true;
            selRol.disabled = false;
            modal.classList.add('abierto');
            document.getElementById('nombre_completo').focus();
        }

        function abrirEditar(boton) {
            const d = boton.dataset;
            formulario.reset();
            document.getElementById('accion').value = 'editar';
            document.getElementById('id_usuario').value = d.id;
            document.getElementById('nombre_completo').value = d.nombre;
            document.getElementById('correo').value = d.correo;
            selRol.value = d.rol;
            selRol.disabled = d.yo === '1';
            document.getElementById('modal-titulo').textContent = 'Editar usuario';
            document.getElementById('btn-guardar').textContent = 'Guardar cambios';
            document.getElementById('etiqueta-clave').textContent = 'Nueva contraseña (opcional)';
            document.getElementById('ayuda-clave').textContent = 'Déjela vacía para conservar la actual.';
            campoClave.required = false;
            modal.classList.add('abierto');
            document.getElementById('nombre_completo').focus();
        }

        function cerrarModal() {
            modal.classList.remove('abierto');
        }

        formulario.addEventListener('submit', function () {
            selRol.disabled = false;
        });

        modal.addEventListener('click', function (ev) {
            if (ev.target === modal) {
                cerrarModal();
            }
        });

        document.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape') {
                cerrarModal();
            }
        });
    </script>

</body>
</html>
