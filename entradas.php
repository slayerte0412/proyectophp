<?php
require_once "auth.php";
require_once "conexion.php";
requerir_admin();

class EntradaError extends Exception
{
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    csrf_validar();

    $id_proveedor = filter_var($_POST['id_proveedor'] ?? '', FILTER_VALIDATE_INT);
    $observacion = trim($_POST['observacion'] ?? '');
    $lista_productos = is_array($_POST['producto'] ?? null) ? $_POST['producto'] : [];
    $lista_cantidades = is_array($_POST['cantidad'] ?? null) ? $_POST['cantidad'] : [];
    $lista_precios = is_array($_POST['precio'] ?? null) ? $_POST['precio'] : [];

    $items = [];
    $datos_validos = true;

    foreach ($lista_productos as $i => $valor) {
        $id_producto = filter_var($valor, FILTER_VALIDATE_INT);
        if (!$id_producto) {
            continue;
        }

        $cantidad = filter_var($lista_cantidades[$i] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);
        $precio = str_replace(',', '.', trim((string)($lista_precios[$i] ?? '')));

        if ($cantidad === false || !preg_match('/^\d{1,8}(\.\d{1,2})?$/', $precio)) {
            $datos_validos = false;
            break;
        }

        $items[] = ['id' => $id_producto, 'cantidad' => $cantidad, 'precio' => $precio];
    }

    if (!$id_proveedor) {
        flash('error', 'Seleccione un proveedor.');
    } elseif (!$datos_validos) {
        flash('error', 'Revise las filas: la cantidad debe ser un entero mayor a 0 y el precio un número válido.');
    } elseif (empty($items)) {
        flash('error', 'Agregue al menos un producto a la entrada.');
    } elseif (mb_strlen($observacion) > 255) {
        flash('error', 'La observación no puede superar 255 caracteres.');
    } else {
        try {
            $conexion->beginTransaction();

            $st = $conexion->prepare("SELECT COUNT(*) FROM proveedores WHERE Id_proveedor = ? AND Activo = 1");
            $st->execute([$id_proveedor]);
            if ((int)$st->fetchColumn() === 0) {
                throw new EntradaError("El proveedor seleccionado no existe.");
            }

            $total = 0;
            foreach ($items as $it) {
                $total += round((float)$it['precio'] * $it['cantidad'], 2);
            }
            $total = round($total, 2);

            $st = $conexion->prepare(
                "INSERT INTO entradas_inventario (Fecha_entrada, Id_proveedor, Id_usuario, Total, Observacion)
                 VALUES (NOW(), ?, ?, ?, ?)"
            );
            $st->execute([$id_proveedor, $_SESSION['Id_usuario'], $total, $observacion !== '' ? $observacion : null]);
            $id_entrada = (int)$conexion->lastInsertId();

            $st_det = $conexion->prepare(
                "INSERT INTO detalle_entradas (Id_entrada, Id_producto, Cantidad, Precio_compra, Subtotal)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $st_stock = $conexion->prepare(
                "UPDATE productos SET Stock = Stock + ?, Precio_compra = ? WHERE Id_producto = ? AND Activo = 1"
            );

            foreach ($items as $it) {
                $subtotal = round((float)$it['precio'] * $it['cantidad'], 2);
                $st_det->execute([$id_entrada, $it['id'], $it['cantidad'], $it['precio'], $subtotal]);
                $st_stock->execute([$it['cantidad'], $it['precio'], $it['id']]);

                if ($st_stock->rowCount() !== 1) {
                    throw new EntradaError("Uno de los productos ya no está disponible.");
                }
            }

            $conexion->commit();
            flash('exito', 'Entrada #' . $id_entrada . ' registrada por Bs. ' . number_format($total, 2) . '. El stock fue actualizado.');
        } catch (EntradaError $ex) {
            if ($conexion->inTransaction()) {
                $conexion->rollBack();
            }
            flash('error', $ex->getMessage());
        } catch (Exception $ex) {
            if ($conexion->inTransaction()) {
                $conexion->rollBack();
            }
            error_log("Error en entradas.php: " . $ex->getMessage());
            flash('error', 'No se pudo registrar la entrada. Intente nuevamente.');
        }
    }

    header("Location: entradas.php");
    exit();
}

$proveedores = $conexion->query("SELECT Id_proveedor, Nombre_empresa FROM proveedores WHERE Activo = 1 ORDER BY Nombre_empresa")->fetchAll();

$productos = $conexion->query(
    "SELECT p.Id_producto, p.Nombre, p.Precio_compra, p.Stock, m.Nombre AS Marca
     FROM productos p
     JOIN marcas m ON m.Id_marca = p.Id_marca
     WHERE p.Activo = 1
     ORDER BY p.Nombre"
)->fetchAll();

$entradas = $conexion->query(
    "SELECT e.Id_entrada, e.Fecha_entrada, e.Total, e.Observacion, pv.Nombre_empresa, u.Nombre_completo
     FROM entradas_inventario e
     JOIN proveedores pv ON pv.Id_proveedor = e.Id_proveedor
     JOIN usuarios u ON u.Id_usuario = e.Id_usuario
     ORDER BY e.Id_entrada DESC
     LIMIT 20"
)->fetchAll();

$detalles = [];
if (!empty($entradas)) {
    $ids = array_column($entradas, 'Id_entrada');
    $marcadores = implode(',', array_fill(0, count($ids), '?'));
    $st = $conexion->prepare(
        "SELECT de.Id_entrada, de.Cantidad, de.Precio_compra, de.Subtotal, p.Nombre
         FROM detalle_entradas de
         JOIN productos p ON p.Id_producto = de.Id_producto
         WHERE de.Id_entrada IN ($marcadores)
         ORDER BY p.Nombre"
    );
    $st->execute($ids);
    foreach ($st->fetchAll() as $d) {
        $detalles[$d['Id_entrada']][] = $d;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Entradas - Lazaroni</title>
    <link rel="stylesheet" href="estilo.css">
</head>
<body>

    <?php include "nav.php"; ?>

    <main class="contenedor">
        <h1 class="titulo-pagina">Entradas de Inventario</h1>
        <p class="subtitulo">Registre las compras a proveedores para aumentar el stock</p>

        <?php mostrar_flash(); ?>

        <section class="panel">
            <h2 class="panel-titulo">Registrar nueva entrada</h2>
            <p class="panel-subtitulo">Complete solo las filas que necesite (hasta 5 productos por entrada)</p>

            <form method="POST" action="entradas.php">
                <?php echo csrf_campo(); ?>

                <div class="form-grid" style="margin-bottom: 22px;">
                    <div class="campo">
                        <label for="id_proveedor">Proveedor</label>
                        <select id="id_proveedor" name="id_proveedor" required>
                            <option value="">Seleccione un proveedor</option>
                            <?php foreach ($proveedores as $pv): ?>
                                <option value="<?php echo (int)$pv['Id_proveedor']; ?>"><?php echo e($pv['Nombre_empresa']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="campo">
                        <label for="observacion">Observación (opcional)</label>
                        <input type="text" id="observacion" name="observacion" maxlength="255" placeholder="Ej. Factura 0045">
                    </div>
                </div>

                <?php for ($i = 0; $i < 5; $i++): ?>
                    <div class="fila-entrada">
                        <select name="producto[]" class="sel-producto" aria-label="Producto">
                            <option value="">Seleccione un producto</option>
                            <?php foreach ($productos as $pr): ?>
                                <option value="<?php echo (int)$pr['Id_producto']; ?>" data-precio="<?php echo e($pr['Precio_compra']); ?>">
                                    <?php echo e($pr['Nombre'] . ' - ' . $pr['Marca'] . ' (stock: ' . $pr['Stock'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="number" name="cantidad[]" min="1" step="1" placeholder="Cantidad" aria-label="Cantidad">
                        <input type="number" name="precio[]" class="in-precio" min="0" step="0.01" placeholder="Precio de compra (Bs)" aria-label="Precio de compra">
                    </div>
                <?php endfor; ?>

                <div class="acciones-modal">
                    <button type="submit" class="btn btn-primario">Registrar entrada</button>
                </div>
            </form>
        </section>

        <section class="panel">
            <h2 class="panel-titulo">Últimas entradas registradas</h2>
            <p class="panel-subtitulo">Se muestran las 20 más recientes</p>

            <div class="tabla-scroll">
                <table class="tabla">
                    <thead>
                        <tr>
                            <th class="centro">N°</th>
                            <th>Fecha</th>
                            <th>Proveedor</th>
                            <th>Productos recibidos</th>
                            <th class="num">Total</th>
                            <th>Registrado por</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($entradas)): ?>
                            <tr><td colspan="6" class="vacio">Aún no hay entradas registradas.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($entradas as $en):
                            $marca_tiempo = strtotime($en['Fecha_entrada']);
                        ?>
                        <tr>
                            <td class="centro"><strong><?php echo (int)$en['Id_entrada']; ?></strong></td>
                            <td>
                                <?php echo e(date('d/m/Y', $marca_tiempo)); ?>
                                <span class="sub"><?php echo e(date('H:i', $marca_tiempo)); ?> hs</span>
                            </td>
                            <td>
                                <?php echo e($en['Nombre_empresa']); ?>
                                <?php if ($en['Observacion'] !== null && $en['Observacion'] !== ''): ?>
                                    <span class="sub"><?php echo e($en['Observacion']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="lineas">
                                    <?php foreach ($detalles[$en['Id_entrada']] ?? [] as $l): ?>
                                        <div class="linea">
                                            <span class="cant"><?php echo (int)$l['Cantidad']; ?> ×</span>
                                            <span class="nom"><?php echo e($l['Nombre']); ?> <span class="sub">Bs. <?php echo number_format($l['Precio_compra'], 2); ?> c/u</span></span>
                                            <span class="monto">Bs. <?php echo number_format($l['Subtotal'], 2); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                            <td class="num"><strong>Bs. <?php echo number_format($en['Total'], 2); ?></strong></td>
                            <td><?php echo e($en['Nombre_completo']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>

    <script>
        document.querySelectorAll('.sel-producto').forEach(function (sel) {
            sel.addEventListener('change', function () {
                const opcion = sel.options[sel.selectedIndex];
                const precio = sel.closest('.fila-entrada').querySelector('.in-precio');
                if (opcion.dataset.precio && precio.value === '') {
                    precio.value = opcion.dataset.precio;
                }
            });
        });
    </script>

</body>
</html>
