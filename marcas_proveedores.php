<?php
require_once "auth.php";
require_once "conexion.php";
requerir_admin();

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    csrf_validar();

    $entidad = $_POST['entidad'] ?? '';
    $accion = $_POST['accion'] ?? '';

    if ($entidad === 'marca') {
        $id = filter_var($_POST['id_marca'] ?? '', FILTER_VALIDATE_INT);
        $nombre = trim($_POST['nombre'] ?? '');

        if ($accion === 'eliminar') {
            if (!$id) {
                flash('error', 'Marca no válida.');
            } else {
                $st = $conexion->prepare("SELECT COUNT(*) FROM productos WHERE Id_marca = ? AND Activo = 1");
                $st->execute([$id]);
                if ((int)$st->fetchColumn() > 0) {
                    flash('error', 'No se puede eliminar: la marca tiene productos activos. Cambie la marca de esos productos primero.');
                } else {
                    $conexion->prepare("UPDATE marcas SET Activo = 0 WHERE Id_marca = ?")->execute([$id]);
                    flash('exito', 'Marca eliminada correctamente.');
                }
            }
        } elseif ($accion === 'crear' || $accion === 'editar') {
            if ($nombre === '' || mb_strlen($nombre) > 80) {
                flash('error', 'El nombre de la marca es obligatorio (máximo 80 caracteres).');
            } else {
                try {
                    if ($accion === 'crear') {
                        $st = $conexion->prepare("SELECT Id_marca, Activo FROM marcas WHERE Nombre = ?");
                        $st->execute([$nombre]);
                        $existente = $st->fetch();

                        if ($existente && (int)$existente['Activo'] === 1) {
                            flash('error', 'Ya existe una marca con ese nombre.');
                        } elseif ($existente) {
                            $conexion->prepare("UPDATE marcas SET Nombre = ?, Activo = 1 WHERE Id_marca = ?")->execute([$nombre, $existente['Id_marca']]);
                            flash('exito', 'Marca agregada correctamente.');
                        } else {
                            $conexion->prepare("INSERT INTO marcas (Nombre) VALUES (?)")->execute([$nombre]);
                            flash('exito', 'Marca agregada correctamente.');
                        }
                    } elseif (!$id) {
                        flash('error', 'Marca no válida.');
                    } else {
                        $st = $conexion->prepare("SELECT Id_marca FROM marcas WHERE Nombre = ? AND Id_marca <> ?");
                        $st->execute([$nombre, $id]);
                        if ($st->fetch()) {
                            flash('error', 'Ya existe otra marca con ese nombre.');
                        } else {
                            $conexion->prepare("UPDATE marcas SET Nombre = ? WHERE Id_marca = ?")->execute([$nombre, $id]);
                            flash('exito', 'Marca actualizada correctamente.');
                        }
                    }
                } catch (Exception $ex) {
                    error_log("Error en marcas_proveedores.php: " . $ex->getMessage());
                    flash('error', 'No se pudo guardar la marca. Intente nuevamente.');
                }
            }
        }
    }

    if ($entidad === 'proveedor') {
        $id = filter_var($_POST['id_proveedor'] ?? '', FILTER_VALIDATE_INT);
        $nombre = trim($_POST['nombre_empresa'] ?? '');
        $contacto = trim($_POST['contacto'] ?? '');
        $telefono = trim($_POST['telefono'] ?? '');

        if ($accion === 'eliminar') {
            if (!$id) {
                flash('error', 'Proveedor no válido.');
            } else {
                $st = $conexion->prepare("SELECT COUNT(*) FROM productos WHERE Id_proveedor = ? AND Activo = 1");
                $st->execute([$id]);
                if ((int)$st->fetchColumn() > 0) {
                    flash('error', 'No se puede eliminar: el proveedor tiene productos activos. Cambie el proveedor de esos productos primero.');
                } else {
                    $conexion->prepare("UPDATE proveedores SET Activo = 0 WHERE Id_proveedor = ?")->execute([$id]);
                    flash('exito', 'Proveedor eliminado correctamente.');
                }
            }
        } elseif ($accion === 'crear' || $accion === 'editar') {
            $errores = [];

            if ($nombre === '' || mb_strlen($nombre) > 100) {
                $errores[] = "El nombre de la empresa es obligatorio (máximo 100 caracteres).";
            }
            if (mb_strlen($contacto) > 100) {
                $errores[] = "El contacto no puede superar 100 caracteres.";
            }
            if (mb_strlen($telefono) > 20 || !preg_match('/^[0-9+\-\s]*$/', $telefono)) {
                $errores[] = "El teléfono solo puede tener números, espacios, + y - (máximo 20 caracteres).";
            }
            if ($accion === 'editar' && !$id) {
                $errores[] = "Proveedor no válido.";
            }

            if (!empty($errores)) {
                flash('error', implode(' ', $errores));
            } else {
                try {
                    if ($accion === 'crear') {
                        $st = $conexion->prepare("SELECT COUNT(*) FROM proveedores WHERE Nombre_empresa = ? AND Activo = 1");
                        $st->execute([$nombre]);
                        if ((int)$st->fetchColumn() > 0) {
                            flash('error', 'Ya existe un proveedor con ese nombre.');
                        } else {
                            $conexion->prepare("INSERT INTO proveedores (Nombre_empresa, Contacto, Telefono) VALUES (?, ?, ?)")->execute([$nombre, $contacto, $telefono]);
                            flash('exito', 'Proveedor agregado correctamente.');
                        }
                    } else {
                        $st = $conexion->prepare("SELECT COUNT(*) FROM proveedores WHERE Nombre_empresa = ? AND Activo = 1 AND Id_proveedor <> ?");
                        $st->execute([$nombre, $id]);
                        if ((int)$st->fetchColumn() > 0) {
                            flash('error', 'Ya existe otro proveedor con ese nombre.');
                        } else {
                            $conexion->prepare("UPDATE proveedores SET Nombre_empresa = ?, Contacto = ?, Telefono = ? WHERE Id_proveedor = ?")->execute([$nombre, $contacto, $telefono, $id]);
                            flash('exito', 'Proveedor actualizado correctamente.');
                        }
                    }
                } catch (Exception $ex) {
                    error_log("Error en marcas_proveedores.php: " . $ex->getMessage());
                    flash('error', 'No se pudo guardar el proveedor. Intente nuevamente.');
                }
            }
        }
    }

    header("Location: marcas_proveedores.php");
    exit();
}

$q = trim($_GET['q'] ?? '');
$ver = $_GET['ver'] ?? 'todos';
if (!in_array($ver, ['todos', 'marcas', 'proveedores'], true)) {
    $ver = 'todos';
}

$marcas = [];
$proveedores = [];

if ($ver !== 'proveedores') {
    $where = "m.Activo = 1";
    $params = [];
    if ($q !== '') {
        $where .= " AND m.Nombre LIKE ?";
        $params[] = "%" . $q . "%";
    }
    $st = $conexion->prepare(
        "SELECT m.Id_marca, m.Nombre, m.Fecha_registro, COUNT(p.Id_producto) AS productos
         FROM marcas m
         LEFT JOIN productos p ON p.Id_marca = m.Id_marca AND p.Activo = 1
         WHERE $where
         GROUP BY m.Id_marca, m.Nombre, m.Fecha_registro
         ORDER BY m.Nombre"
    );
    $st->execute($params);
    $marcas = $st->fetchAll();
}

if ($ver !== 'marcas') {
    $where = "pv.Activo = 1";
    $params = [];
    if ($q !== '') {
        $where .= " AND (pv.Nombre_empresa LIKE ? OR pv.Contacto LIKE ? OR pv.Telefono LIKE ?)";
        $like = "%" . $q . "%";
        array_push($params, $like, $like, $like);
    }
    $st = $conexion->prepare(
        "SELECT pv.Id_proveedor, pv.Nombre_empresa, pv.Contacto, pv.Telefono, pv.Fecha_registro, COUNT(p.Id_producto) AS productos
         FROM proveedores pv
         LEFT JOIN productos p ON p.Id_proveedor = pv.Id_proveedor AND p.Activo = 1
         WHERE $where
         GROUP BY pv.Id_proveedor, pv.Nombre_empresa, pv.Contacto, pv.Telefono, pv.Fecha_registro
         ORDER BY pv.Nombre_empresa"
    );
    $st->execute($params);
    $proveedores = $st->fetchAll();
}

$hay_filtro = ($q !== '' || $ver !== 'todos');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Marcas y Proveedores - Lazaroni</title>
    <link rel="stylesheet" href="estilo.css">
</head>
<body>

    <?php include "nav.php"; ?>

    <main class="contenedor">
        <h1 class="titulo-pagina">Marcas y Proveedores</h1>
        <p class="subtitulo">Administre las marcas de los productos y las empresas que abastecen el inventario</p>

        <?php mostrar_flash(); ?>

        <section class="panel">
            <h2 class="panel-titulo solo">Buscar y agregar</h2>

            <div class="barra-herramientas">
                <form method="GET" action="marcas_proveedores.php" class="buscador">
                    <input type="search" name="q" value="<?php echo e($q); ?>" placeholder="Buscar por nombre, contacto o teléfono">
                    <select name="ver">
                        <option value="todos" <?php echo $ver === 'todos' ? 'selected' : ''; ?>>Marcas y proveedores</option>
                        <option value="marcas" <?php echo $ver === 'marcas' ? 'selected' : ''; ?>>Solo marcas</option>
                        <option value="proveedores" <?php echo $ver === 'proveedores' ? 'selected' : ''; ?>>Solo proveedores</option>
                    </select>
                    <button type="submit" class="btn btn-secundario">Buscar</button>
                    <?php if ($hay_filtro): ?>
                        <a href="marcas_proveedores.php" class="btn btn-secundario">Mostrar todos</a>
                    <?php endif; ?>
                </form>

                <div class="acciones">
                    <button type="button" class="btn btn-primario" onclick="abrirMarca(null)">+ Agregar marca</button>
                    <button type="button" class="btn btn-primario" onclick="abrirProveedor(null)">+ Agregar proveedor</button>
                </div>
            </div>
        </section>

        <?php if ($ver !== 'proveedores'): ?>
        <section class="panel">
            <h2 class="panel-titulo">Marcas</h2>
            <p class="panel-subtitulo"><?php echo count($marcas); ?> marca(s) <?php echo $q !== '' ? 'encontrada(s)' : 'registrada(s)'; ?></p>

            <div class="tabla-scroll">
                <table class="tabla">
                    <thead>
                        <tr>
                            <th>Marca</th>
                            <th class="centro">Productos activos</th>
                            <th class="centro">Registro</th>
                            <th class="centro">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($marcas)): ?>
                            <tr><td colspan="4" class="vacio">No se encontraron marcas.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($marcas as $m): ?>
                        <tr>
                            <td><strong><?php echo e($m['Nombre']); ?></strong></td>
                            <td class="centro"><?php echo (int)$m['productos']; ?></td>
                            <td class="centro"><?php echo e(date('d/m/Y', strtotime($m['Fecha_registro']))); ?></td>
                            <td>
                                <div class="acciones">
                                    <button type="button" class="btn btn-secundario btn-chico"
                                        data-id="<?php echo (int)$m['Id_marca']; ?>"
                                        data-nombre="<?php echo e($m['Nombre']); ?>"
                                        onclick="abrirMarca(this)">Editar</button>
                                    <form method="POST" action="marcas_proveedores.php" onsubmit="return confirm('¿Eliminar esta marca?');">
                                        <?php echo csrf_campo(); ?>
                                        <input type="hidden" name="entidad" value="marca">
                                        <input type="hidden" name="accion" value="eliminar">
                                        <input type="hidden" name="id_marca" value="<?php echo (int)$m['Id_marca']; ?>">
                                        <button type="submit" class="btn btn-peligro btn-chico">Eliminar</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($ver !== 'marcas'): ?>
        <section class="panel">
            <h2 class="panel-titulo">Proveedores</h2>
            <p class="panel-subtitulo"><?php echo count($proveedores); ?> proveedor(es) <?php echo $q !== '' ? 'encontrado(s)' : 'registrado(s)'; ?></p>

            <div class="tabla-scroll">
                <table class="tabla">
                    <thead>
                        <tr>
                            <th>Empresa</th>
                            <th>Contacto</th>
                            <th>Teléfono</th>
                            <th class="centro">Productos activos</th>
                            <th class="centro">Registro</th>
                            <th class="centro">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($proveedores)): ?>
                            <tr><td colspan="6" class="vacio">No se encontraron proveedores.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($proveedores as $pv): ?>
                        <tr>
                            <td><strong><?php echo e($pv['Nombre_empresa']); ?></strong></td>
                            <td><?php echo e($pv['Contacto'] !== '' && $pv['Contacto'] !== null ? $pv['Contacto'] : 'Sin contacto'); ?></td>
                            <td><?php echo e($pv['Telefono'] !== '' && $pv['Telefono'] !== null ? $pv['Telefono'] : 'Sin teléfono'); ?></td>
                            <td class="centro"><?php echo (int)$pv['productos']; ?></td>
                            <td class="centro"><?php echo e(date('d/m/Y', strtotime($pv['Fecha_registro']))); ?></td>
                            <td>
                                <div class="acciones">
                                    <button type="button" class="btn btn-secundario btn-chico"
                                        data-id="<?php echo (int)$pv['Id_proveedor']; ?>"
                                        data-nombre="<?php echo e($pv['Nombre_empresa']); ?>"
                                        data-contacto="<?php echo e($pv['Contacto']); ?>"
                                        data-telefono="<?php echo e($pv['Telefono']); ?>"
                                        onclick="abrirProveedor(this)">Editar</button>
                                    <form method="POST" action="marcas_proveedores.php" onsubmit="return confirm('¿Eliminar este proveedor?');">
                                        <?php echo csrf_campo(); ?>
                                        <input type="hidden" name="entidad" value="proveedor">
                                        <input type="hidden" name="accion" value="eliminar">
                                        <input type="hidden" name="id_proveedor" value="<?php echo (int)$pv['Id_proveedor']; ?>">
                                        <button type="submit" class="btn btn-peligro btn-chico">Eliminar</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php endif; ?>
    </main>

    <div class="modal-fondo" id="modal-marca">
        <div class="modal chico">
            <div class="modal-cabecera">
                <h3 id="titulo-marca">Agregar marca</h3>
                <button type="button" class="modal-cerrar" onclick="cerrarModales()" aria-label="Cerrar">&times;</button>
            </div>
            <div class="modal-cuerpo">
                <form method="POST" action="marcas_proveedores.php" id="form-marca">
                    <?php echo csrf_campo(); ?>
                    <input type="hidden" name="entidad" value="marca">
                    <input type="hidden" name="accion" id="accion-marca" value="crear">
                    <input type="hidden" name="id_marca" id="id_marca" value="">

                    <div class="campo">
                        <label for="nombre">Nombre de la marca</label>
                        <input type="text" id="nombre" name="nombre" maxlength="80" placeholder="Ej. Don Vittorio" required>
                    </div>

                    <div class="acciones-modal">
                        <button type="button" class="btn btn-secundario" onclick="cerrarModales()">Cancelar</button>
                        <button type="submit" class="btn btn-primario" id="guardar-marca">Guardar marca</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal-fondo" id="modal-proveedor">
        <div class="modal chico">
            <div class="modal-cabecera">
                <h3 id="titulo-proveedor">Agregar proveedor</h3>
                <button type="button" class="modal-cerrar" onclick="cerrarModales()" aria-label="Cerrar">&times;</button>
            </div>
            <div class="modal-cuerpo">
                <form method="POST" action="marcas_proveedores.php" id="form-proveedor">
                    <?php echo csrf_campo(); ?>
                    <input type="hidden" name="entidad" value="proveedor">
                    <input type="hidden" name="accion" id="accion-proveedor" value="crear">
                    <input type="hidden" name="id_proveedor" id="id_proveedor" value="">

                    <div class="form-grid">
                        <div class="campo ancho">
                            <label for="nombre_empresa">Nombre de la empresa</label>
                            <input type="text" id="nombre_empresa" name="nombre_empresa" maxlength="100" placeholder="Ej. Distribuidora Famosa SRL" required>
                        </div>
                        <div class="campo">
                            <label for="contacto">Persona de contacto</label>
                            <input type="text" id="contacto" name="contacto" maxlength="100" placeholder="Ej. Juan Perez">
                        </div>
                        <div class="campo">
                            <label for="telefono">Teléfono</label>
                            <input type="text" id="telefono" name="telefono" maxlength="20" placeholder="Ej. 70012345">
                        </div>
                    </div>

                    <div class="acciones-modal">
                        <button type="button" class="btn btn-secundario" onclick="cerrarModales()">Cancelar</button>
                        <button type="submit" class="btn btn-primario" id="guardar-proveedor">Guardar proveedor</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        const modalMarca = document.getElementById('modal-marca');
        const modalProveedor = document.getElementById('modal-proveedor');

        function abrirMarca(boton) {
            document.getElementById('form-marca').reset();
            const editar = boton !== null;
            document.getElementById('accion-marca').value = editar ? 'editar' : 'crear';
            document.getElementById('id_marca').value = editar ? boton.dataset.id : '';
            document.getElementById('nombre').value = editar ? boton.dataset.nombre : '';
            document.getElementById('titulo-marca').textContent = editar ? 'Editar marca' : 'Agregar marca';
            document.getElementById('guardar-marca').textContent = editar ? 'Guardar cambios' : 'Guardar marca';
            modalMarca.classList.add('abierto');
            document.getElementById('nombre').focus();
        }

        function abrirProveedor(boton) {
            document.getElementById('form-proveedor').reset();
            const editar = boton !== null;
            document.getElementById('accion-proveedor').value = editar ? 'editar' : 'crear';
            document.getElementById('id_proveedor').value = editar ? boton.dataset.id : '';
            document.getElementById('nombre_empresa').value = editar ? boton.dataset.nombre : '';
            document.getElementById('contacto').value = editar ? boton.dataset.contacto : '';
            document.getElementById('telefono').value = editar ? boton.dataset.telefono : '';
            document.getElementById('titulo-proveedor').textContent = editar ? 'Editar proveedor' : 'Agregar proveedor';
            document.getElementById('guardar-proveedor').textContent = editar ? 'Guardar cambios' : 'Guardar proveedor';
            modalProveedor.classList.add('abierto');
            document.getElementById('nombre_empresa').focus();
        }

        function cerrarModales() {
            modalMarca.classList.remove('abierto');
            modalProveedor.classList.remove('abierto');
        }

        [modalMarca, modalProveedor].forEach(function (m) {
            m.addEventListener('click', function (ev) {
                if (ev.target === m) {
                    cerrarModales();
                }
            });
        });

        document.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape') {
                cerrarModales();
            }
        });
    </script>

</body>
</html>
