<?php
require_once "auth.php";
require_once "conexion.php";
requerir_login();

function entero_valido($valor, $min = 0, $max = 1000000)
{
    $r = filter_var($valor, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
    return $r === false ? null : $r;
}

function decimal_valido($valor)
{
    $valor = str_replace(',', '.', trim((string)$valor));
    if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $valor)) {
        return null;
    }
    return $valor;
}

function preparar_imagen($archivo)
{
    if (!is_array($archivo) || $archivo['error'] === UPLOAD_ERR_NO_FILE) {
        return [null, null, null];
    }
    if ($archivo['error'] !== UPLOAD_ERR_OK || $archivo['size'] > 5 * 1024 * 1024) {
        return [null, null, 'No se pudo subir la imagen. Use una foto de menos de 5 MB.'];
    }

    $info = @getimagesize($archivo['tmp_name']);
    $permitidos = ['image/jpeg', 'image/png', 'image/webp'];
    if (!$info || !in_array($info['mime'], $permitidos, true)) {
        return [null, null, 'La imagen debe ser JPG, PNG o WEBP.'];
    }

    $contenido = file_get_contents($archivo['tmp_name']);

    if (function_exists('imagecreatefromstring') && function_exists('imagejpeg')) {
        $origen = @imagecreatefromstring($contenido);
        if ($origen) {
            $ancho = imagesx($origen);
            $alto = imagesy($origen);
            $escala = min(1, 800 / max($ancho, $alto));
            $nuevo_ancho = max(1, (int)round($ancho * $escala));
            $nuevo_alto = max(1, (int)round($alto * $escala));

            $lienzo = imagecreatetruecolor($nuevo_ancho, $nuevo_alto);
            imagefill($lienzo, 0, 0, imagecolorallocate($lienzo, 255, 255, 255));
            imagecopyresampled($lienzo, $origen, 0, 0, 0, 0, $nuevo_ancho, $nuevo_alto, $ancho, $alto);

            ob_start();
            imagejpeg($lienzo, null, 85);
            $contenido = ob_get_clean();

            return [$contenido, 'image/jpeg', null];
        }
    }

    if (strlen($contenido) > 1024 * 1024) {
        return [null, null, 'La imagen supera 1 MB. Reduzca su tamaño e intente de nuevo.'];
    }
    return [$contenido, $info['mime'], null];
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    requerir_admin();
    csrf_validar();

    $accion = $_POST['accion'] ?? '';

    if ($accion === 'eliminar') {
        $id = entero_valido($_POST['id_producto'] ?? '', 1, 2147483647);
        if ($id) {
            try {
                $conexion->beginTransaction();
                $conexion->prepare("UPDATE productos SET Activo = 0 WHERE Id_producto = ?")->execute([$id]);
                $conexion->prepare("DELETE FROM imagenes_productos WHERE Id_producto = ?")->execute([$id]);
                $conexion->commit();
                flash('exito', 'Producto eliminado del inventario. Su historial de ventas se conserva.');
            } catch (Exception $ex) {
                if ($conexion->inTransaction()) {
                    $conexion->rollBack();
                }
                error_log("Error en productos.php: " . $ex->getMessage());
                flash('error', 'No se pudo eliminar el producto. Intente nuevamente.');
            }
        } else {
            flash('error', 'Producto no valido.');
        }
        header("Location: productos.php");
        exit();
    }

    if ($accion === 'crear' || $accion === 'editar') {
        $errores = [];

        $nombre = trim($_POST['nombre'] ?? '');
        $tipo_fideo = trim($_POST['tipo_fideo'] ?? '');
        $precio_compra = decimal_valido($_POST['precio_compra'] ?? '');
        $precio_venta = decimal_valido($_POST['precio_venta'] ?? '');
        $stock = entero_valido($_POST['stock'] ?? '', 0, 1000000);
        $stock_minimo = entero_valido($_POST['stock_minimo'] ?? '', 0, 1000000);
        $marca_sel = $_POST['id_marca'] ?? '';
        $nueva_marca = trim($_POST['nueva_marca'] ?? '');
        $prov_sel = $_POST['id_proveedor'] ?? '';
        $id_producto = entero_valido($_POST['id_producto'] ?? '', 1, 2147483647);

        if ($nombre === '' || mb_strlen($nombre) > 100) {
            $errores[] = "El nombre es obligatorio (máximo 100 caracteres).";
        }
        if ($tipo_fideo === '' || mb_strlen($tipo_fideo) > 50) {
            $errores[] = "El tipo es obligatorio (máximo 50 caracteres).";
        }
        if ($precio_compra === null || $precio_venta === null) {
            $errores[] = "Los precios deben ser números válidos, con máximo 2 decimales.";
        }
        if ($stock === null || $stock_minimo === null) {
            $errores[] = "El stock y el stock mínimo deben ser números enteros iguales o mayores a 0.";
        }

        if ($accion === 'editar') {
            if (!$id_producto) {
                $errores[] = "Producto no válido.";
            } else {
                $st = $conexion->prepare("SELECT Id_producto FROM productos WHERE Id_producto = ? AND Activo = 1");
                $st->execute([$id_producto]);
                if (!$st->fetch()) {
                    $errores[] = "El producto ya no existe.";
                }
            }
        }

        $id_marca = null;
        if ($marca_sel === 'nueva') {
            if ($nueva_marca === '' || mb_strlen($nueva_marca) > 80) {
                $errores[] = "Escriba el nombre de la nueva marca (máximo 80 caracteres).";
            }
        } else {
            $id_marca = entero_valido($marca_sel, 1, 2147483647);
            if (!$id_marca) {
                $errores[] = "Seleccione una marca.";
            } else {
                $st = $conexion->prepare("SELECT Id_marca FROM marcas WHERE Id_marca = ? AND Activo = 1");
                $st->execute([$id_marca]);
                if (!$st->fetch()) {
                    $errores[] = "La marca seleccionada no existe.";
                }
            }
        }

        $id_proveedor = null;
        if ($prov_sel !== '') {
            $id_proveedor = entero_valido($prov_sel, 1, 2147483647);
            if (!$id_proveedor) {
                $errores[] = "Proveedor no válido.";
            } else {
                $st = $conexion->prepare("SELECT Id_proveedor FROM proveedores WHERE Id_proveedor = ? AND Activo = 1");
                $st->execute([$id_proveedor]);
                if (!$st->fetch()) {
                    $errores[] = "El proveedor seleccionado no existe.";
                }
            }
        }

        list($contenido, $tipo_imagen, $error_imagen) = preparar_imagen($_FILES['imagen'] ?? null);
        if ($error_imagen !== null) {
            $errores[] = $error_imagen;
        } elseif ($accion === 'crear' && $contenido === null) {
            $errores[] = "Seleccione una imagen para el producto.";
        }

        if (!empty($errores)) {
            flash('error', implode(' ', $errores));
            header("Location: productos.php");
            exit();
        }

        try {
            $conexion->beginTransaction();

            if ($marca_sel === 'nueva') {
                $st = $conexion->prepare("SELECT Id_marca FROM marcas WHERE Nombre = ?");
                $st->execute([$nueva_marca]);
                $existente = $st->fetch();
                if ($existente) {
                    $id_marca = (int)$existente['Id_marca'];
                    $conexion->prepare("UPDATE marcas SET Activo = 1 WHERE Id_marca = ?")->execute([$id_marca]);
                } else {
                    $conexion->prepare("INSERT INTO marcas (Nombre) VALUES (?)")->execute([$nueva_marca]);
                    $id_marca = (int)$conexion->lastInsertId();
                }
            }

            if ($accion === 'crear') {
                $st = $conexion->prepare(
                    "INSERT INTO productos (Nombre, Tipo_fideo, Id_marca, Precio_compra, Precio_venta, Stock, Stock_minimo, Id_proveedor)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $st->execute([$nombre, $tipo_fideo, $id_marca, $precio_compra, $precio_venta, $stock, $stock_minimo, $id_proveedor]);
                $id_guardado = (int)$conexion->lastInsertId();
                $mensaje = "Producto agregado al inventario.";
            } else {
                $st = $conexion->prepare(
                    "UPDATE productos
                     SET Nombre = ?, Tipo_fideo = ?, Id_marca = ?, Precio_compra = ?, Precio_venta = ?, Stock = ?, Stock_minimo = ?, Id_proveedor = ?
                     WHERE Id_producto = ? AND Activo = 1"
                );
                $st->execute([$nombre, $tipo_fideo, $id_marca, $precio_compra, $precio_venta, $stock, $stock_minimo, $id_proveedor, $id_producto]);
                $id_guardado = $id_producto;
                $mensaje = "Producto actualizado correctamente.";
            }

            if ($contenido !== null) {
                $st = $conexion->prepare(
                    "REPLACE INTO imagenes_productos (Id_producto, Tipo, Contenido, Fecha_actualizacion)
                     VALUES (?, ?, ?, NOW())"
                );
                $st->bindValue(1, $id_guardado, PDO::PARAM_INT);
                $st->bindValue(2, $tipo_imagen);
                $st->bindValue(3, $contenido, PDO::PARAM_LOB);
                $st->execute();
            }

            $conexion->commit();
            flash('exito', $mensaje);
        } catch (Exception $ex) {
            if ($conexion->inTransaction()) {
                $conexion->rollBack();
            }
            error_log("Error en productos.php: " . $ex->getMessage());
            flash('error', 'No se pudo guardar el producto. Intente nuevamente.');
        }

        header("Location: productos.php");
        exit();
    }

    header("Location: productos.php");
    exit();
}

$q = trim($_GET['q'] ?? '');
$solo_critico = (($_GET['critico'] ?? '') === '1');

$where = "p.Activo = 1";
$params = [];

if ($q !== '') {
    $where .= " AND (p.Nombre LIKE ? OR p.Tipo_fideo LIKE ? OR m.Nombre LIKE ?)";
    $like = "%" . $q . "%";
    array_push($params, $like, $like, $like);
}
if ($solo_critico) {
    $where .= " AND p.Stock <= p.Stock_minimo";
}

$col_compra = es_admin() ? "p.Precio_compra, pr.Nombre_empresa," : "";

$stmt = $conexion->prepare(
    "SELECT p.Id_producto, p.Nombre, p.Tipo_fideo, p.Id_marca, m.Nombre AS Marca,
            $col_compra
            p.Precio_venta, p.Stock, p.Stock_minimo, p.Id_proveedor, p.Fecha_registro,
            i.Fecha_actualizacion AS Fecha_imagen
     FROM productos p
     JOIN marcas m ON m.Id_marca = p.Id_marca
     LEFT JOIN proveedores pr ON pr.Id_proveedor = p.Id_proveedor
     LEFT JOIN imagenes_productos i ON i.Id_producto = p.Id_producto
     WHERE $where
     ORDER BY p.Nombre ASC"
);
$stmt->execute($params);
$productos = $stmt->fetchAll();

$resumen = $conexion->query(
    "SELECT COUNT(*) AS total,
            COALESCE(SUM(Stock), 0) AS unidades,
            COALESCE(SUM(Stock <= Stock_minimo), 0) AS criticos
     FROM productos
     WHERE Activo = 1"
)->fetch();

$marcas = [];
$proveedores = [];
if (es_admin()) {
    $marcas = $conexion->query("SELECT Id_marca, Nombre FROM marcas WHERE Activo = 1 ORDER BY Nombre")->fetchAll();
    $proveedores = $conexion->query("SELECT Id_proveedor, Nombre_empresa FROM proveedores WHERE Activo = 1 ORDER BY Nombre_empresa")->fetchAll();
}

$columnas = es_admin() ? 10 : 5;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventario - Lazaroni</title>
    <link rel="stylesheet" href="estilo.css">
</head>
<body>

    <?php include "nav.php"; ?>

    <main class="contenedor">
        <h1 class="titulo-pagina">Inventario de Productos</h1>
        <p class="subtitulo">Consulta y control de existencias de fideos y derivados</p>

        <?php mostrar_flash(); ?>

        <section class="resumen">
            <div class="tarjeta-resumen azul">
                <div class="etiqueta">Productos activos a la venta</div>
                <div class="numero"><?php echo (int)$resumen['total']; ?></div>
            </div>
            <div class="tarjeta-resumen verde">
                <div class="etiqueta">Unidades totales en stock</div>
                <div class="numero"><?php echo number_format((int)$resumen['unidades']); ?></div>
            </div>
            <div class="tarjeta-resumen critica">
                <div class="etiqueta">Productos con stock crítico</div>
                <div class="numero"><?php echo (int)$resumen['criticos']; ?></div>
            </div>
        </section>

        <?php if (!es_admin()): ?>
            <div class="aviso-rol">
                Su perfil de <strong>Vendedor</strong> tiene acceso de consulta al inventario y registro de ventas.
            </div>
        <?php endif; ?>

        <section class="panel">
            <h2 class="panel-titulo">Listado de productos</h2>
            <p class="panel-subtitulo"><?php echo count($productos); ?> producto(s) <?php echo ($q !== '' || $solo_critico) ? 'encontrado(s)' : 'registrado(s)'; ?></p>

            <div class="barra-herramientas">
                <form method="GET" action="productos.php" class="buscador">
                    <input type="search" name="q" value="<?php echo e($q); ?>" placeholder="Buscar por nombre, tipo o marca">
                    <label class="check-filtro">
                        <input type="checkbox" name="critico" value="1" <?php echo $solo_critico ? 'checked' : ''; ?>>
                        Solo stock crítico
                    </label>
                    <button type="submit" class="btn btn-secundario">Buscar</button>
                    <?php if ($q !== '' || $solo_critico): ?>
                        <a href="productos.php" class="btn btn-secundario">Mostrar todos</a>
                    <?php endif; ?>
                </form>

                <?php if (es_admin()): ?>
                    <button type="button" class="btn btn-primario" onclick="abrirAgregar()">+ Agregar producto</button>
                <?php endif; ?>
            </div>

            <div class="tabla-scroll">
                <table class="tabla">
                    <thead>
                        <tr>
                            <th>Producto</th>
                            <th>Marca</th>
                            <?php if (es_admin()): ?><th class="num">P. Compra</th><?php endif; ?>
                            <th class="num">P. Venta</th>
                            <th class="centro">Stock</th>
                            <?php if (es_admin()): ?><th class="centro">Alerta en</th><?php endif; ?>
                            <?php if (es_admin()): ?><th>Proveedor</th><?php endif; ?>
                            <th class="centro">Estado</th>
                            <?php if (es_admin()): ?><th class="centro">Registro</th><?php endif; ?>
                            <?php if (es_admin()): ?><th class="centro">Acciones</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($productos)): ?>
                            <tr><td colspan="<?php echo $columnas; ?>" class="vacio">No se encontraron productos.</td></tr>
                        <?php endif; ?>

                        <?php foreach ($productos as $p):
                            $agotado = (int)$p['Stock'] === 0;
                            $critico = (int)$p['Stock'] <= (int)$p['Stock_minimo'];
                            $src = imagen_src($p['Id_producto'], $p['Fecha_imagen']);
                        ?>
                        <tr>
                            <td>
                                <div class="celda-producto">
                                    <?php if ($src !== ''): ?>
                                        <img class="miniatura" src="<?php echo e($src); ?>" alt="<?php echo e($p['Nombre']); ?>" loading="lazy">
                                    <?php else: ?>
                                        <div class="sin-miniatura">Sin imagen</div>
                                    <?php endif; ?>
                                    <div>
                                        <strong><?php echo e($p['Nombre']); ?></strong>
                                        <span class="sub"><?php echo e($p['Tipo_fideo']); ?></span>
                                    </div>
                                </div>
                            </td>
                            <td><?php echo e($p['Marca']); ?></td>
                            <?php if (es_admin()): ?><td class="num">Bs. <?php echo number_format($p['Precio_compra'], 2); ?></td><?php endif; ?>
                            <td class="num">Bs. <?php echo number_format($p['Precio_venta'], 2); ?></td>
                            <td class="centro"><span class="stock-pill"><?php echo (int)$p['Stock']; ?></span></td>
                            <?php if (es_admin()): ?><td class="centro"><?php echo (int)$p['Stock_minimo']; ?></td><?php endif; ?>
                            <?php if (es_admin()): ?><td><?php echo e($p['Nombre_empresa'] ?? 'Sin proveedor'); ?></td><?php endif; ?>
                            <td class="centro">
                                <?php if ($agotado): ?>
                                    <span class="badge badge-agotado">Agotado</span>
                                <?php elseif ($critico): ?>
                                    <span class="badge badge-critico">Stock crítico</span>
                                <?php else: ?>
                                    <span class="badge badge-normal">Normal</span>
                                <?php endif; ?>
                            </td>
                            <?php if (es_admin()): ?><td class="centro"><?php echo e(date('d/m/Y', strtotime($p['Fecha_registro']))); ?></td><?php endif; ?>
                            <?php if (es_admin()): ?>
                            <td>
                                <div class="acciones">
                                    <button type="button" class="btn btn-secundario btn-chico"
                                        data-id="<?php echo (int)$p['Id_producto']; ?>"
                                        data-nombre="<?php echo e($p['Nombre']); ?>"
                                        data-tipo="<?php echo e($p['Tipo_fideo']); ?>"
                                        data-marca="<?php echo (int)$p['Id_marca']; ?>"
                                        data-compra="<?php echo e($p['Precio_compra']); ?>"
                                        data-venta="<?php echo e($p['Precio_venta']); ?>"
                                        data-stock="<?php echo (int)$p['Stock']; ?>"
                                        data-minimo="<?php echo (int)$p['Stock_minimo']; ?>"
                                        data-proveedor="<?php echo e($p['Id_proveedor']); ?>"
                                        data-imagen="<?php echo e($src); ?>"
                                        onclick="abrirEditar(this)">Editar</button>

                                    <form method="POST" action="productos.php" onsubmit="return confirm('¿Eliminar este producto y su imagen del inventario? Su historial de ventas se conservará.');">
                                        <?php echo csrf_campo(); ?>
                                        <input type="hidden" name="accion" value="eliminar">
                                        <input type="hidden" name="id_producto" value="<?php echo (int)$p['Id_producto']; ?>">
                                        <button type="submit" class="btn btn-peligro btn-chico">Eliminar</button>
                                    </form>
                                </div>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>

    <?php if (es_admin()): ?>
    <div class="modal-fondo" id="modal-producto">
        <div class="modal">
            <div class="modal-cabecera">
                <h3 id="modal-titulo">Agregar producto</h3>
                <button type="button" class="modal-cerrar" onclick="cerrarModal()" aria-label="Cerrar">&times;</button>
            </div>

            <div class="modal-cuerpo">
                <form method="POST" action="productos.php" id="form-producto" enctype="multipart/form-data">
                    <?php echo csrf_campo(); ?>
                    <input type="hidden" name="accion" id="accion" value="crear">
                    <input type="hidden" name="id_producto" id="id_producto" value="">

                    <div class="form-grid">
                        <div class="campo ancho">
                            <label for="nombre">Nombre del producto</label>
                            <input type="text" id="nombre" name="nombre" maxlength="100" placeholder="Ej. Fideo Codo 400g" required>
                        </div>

                        <div class="campo">
                            <label for="tipo_fideo">Tipo de fideo</label>
                            <input type="text" id="tipo_fideo" name="tipo_fideo" maxlength="50" placeholder="Ej. Codo, Espagueti, Tallarín" required>
                        </div>

                        <div class="campo">
                            <label for="id_marca">Marca</label>
                            <select id="id_marca" name="id_marca" onchange="actualizarMarca()" required>
                                <option value="">Seleccione una marca</option>
                                <?php foreach ($marcas as $m): ?>
                                    <option value="<?php echo (int)$m['Id_marca']; ?>"><?php echo e($m['Nombre']); ?></option>
                                <?php endforeach; ?>
                                <option value="nueva">+ Agregar una marca nueva</option>
                            </select>
                        </div>

                        <div class="campo ancho" id="campo-nueva-marca" hidden>
                            <label for="nueva_marca">Nombre de la nueva marca</label>
                            <input type="text" id="nueva_marca" name="nueva_marca" maxlength="80" placeholder="Ej. Don Vittorio">
                        </div>

                        <div class="campo">
                            <label for="precio_compra">Precio de compra (Bs)</label>
                            <input type="number" id="precio_compra" name="precio_compra" step="0.01" min="0" placeholder="0.00" required>
                        </div>

                        <div class="campo">
                            <label for="precio_venta">Precio de venta (Bs)</label>
                            <input type="number" id="precio_venta" name="precio_venta" step="0.01" min="0" placeholder="0.00" required>
                        </div>

                        <div class="campo">
                            <label for="stock">Stock actual (unidades)</label>
                            <input type="number" id="stock" name="stock" min="0" step="1" placeholder="0" required>
                        </div>

                        <div class="campo">
                            <label for="stock_minimo">Avisar cuando el stock sea igual o menor a</label>
                            <input type="number" id="stock_minimo" name="stock_minimo" min="0" step="1" value="5" required>
                            <span class="ayuda">Al llegar a esta cantidad, el producto se marcará como stock crítico.</span>
                        </div>

                        <div class="campo ancho">
                            <label for="id_proveedor">Proveedor</label>
                            <select id="id_proveedor" name="id_proveedor">
                                <option value="">Sin proveedor</option>
                                <?php foreach ($proveedores as $pv): ?>
                                    <option value="<?php echo (int)$pv['Id_proveedor']; ?>"><?php echo e($pv['Nombre_empresa']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="campo ancho">
                            <label for="imagen">Imagen del producto</label>
                            <input type="file" id="imagen" name="imagen" accept="image/jpeg,image/png,image/webp">
                            <span class="ayuda" id="ayuda-imagen">Obligatoria. JPG, PNG o WEBP; se ajusta sola a un tamaño liviano.</span>
                            <img id="vista-previa" class="vista-previa" alt="Vista previa" hidden>
                        </div>
                    </div>

                    <div class="acciones-modal">
                        <button type="button" class="btn btn-secundario" onclick="cerrarModal()">Cancelar</button>
                        <button type="submit" class="btn btn-primario" id="btn-guardar">Guardar producto</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        const modal = document.getElementById('modal-producto');
        const form = document.getElementById('form-producto');
        const selMarca = document.getElementById('id_marca');
        const campoNueva = document.getElementById('campo-nueva-marca');
        const inputNueva = document.getElementById('nueva_marca');
        const inputImagen = document.getElementById('imagen');
        const vistaPrevia = document.getElementById('vista-previa');
        const ayudaImagen = document.getElementById('ayuda-imagen');

        function actualizarMarca() {
            const nueva = selMarca.value === 'nueva';
            campoNueva.hidden = !nueva;
            inputNueva.required = nueva;
            if (!nueva) {
                inputNueva.value = '';
            }
        }

        function mostrarVista(src) {
            if (src) {
                vistaPrevia.src = src;
                vistaPrevia.hidden = false;
            } else {
                vistaPrevia.removeAttribute('src');
                vistaPrevia.hidden = true;
            }
        }

        inputImagen.addEventListener('change', function () {
            if (inputImagen.files.length > 0) {
                mostrarVista(URL.createObjectURL(inputImagen.files[0]));
            }
        });

        function abrirAgregar() {
            form.reset();
            document.getElementById('accion').value = 'crear';
            document.getElementById('id_producto').value = '';
            document.getElementById('modal-titulo').textContent = 'Agregar producto';
            document.getElementById('btn-guardar').textContent = 'Guardar producto';
            inputImagen.required = true;
            ayudaImagen.textContent = 'Obligatoria. JPG, PNG o WEBP; se ajusta sola a un tamaño liviano.';
            mostrarVista('');
            actualizarMarca();
            modal.classList.add('abierto');
            document.getElementById('nombre').focus();
        }

        function abrirEditar(boton) {
            const d = boton.dataset;
            form.reset();
            document.getElementById('accion').value = 'editar';
            document.getElementById('id_producto').value = d.id;
            document.getElementById('nombre').value = d.nombre;
            document.getElementById('tipo_fideo').value = d.tipo;
            selMarca.value = d.marca;
            document.getElementById('precio_compra').value = d.compra;
            document.getElementById('precio_venta').value = d.venta;
            document.getElementById('stock').value = d.stock;
            document.getElementById('stock_minimo').value = d.minimo;
            document.getElementById('id_proveedor').value = d.proveedor;
            document.getElementById('modal-titulo').textContent = 'Editar producto';
            document.getElementById('btn-guardar').textContent = 'Guardar cambios';
            inputImagen.required = d.imagen === '';
            ayudaImagen.textContent = d.imagen === ''
                ? 'Este producto aún no tiene imagen. Súbala aquí.'
                : 'Opcional. Suba una nueva solo si desea reemplazar la actual.';
            mostrarVista(d.imagen);
            actualizarMarca();
            modal.classList.add('abierto');
            document.getElementById('nombre').focus();
        }

        function cerrarModal() {
            modal.classList.remove('abierto');
        }

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
    <?php endif; ?>

</body>
</html>
