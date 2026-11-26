<?php
require_once "auth.php";
require_once "conexion.php";
requerir_login();

function fecha_valida($texto)
{
    $d = DateTime::createFromFormat('Y-m-d', $texto);
    return $d && $d->format('Y-m-d') === $texto;
}

$desde = trim($_GET['desde'] ?? '');
$hasta = trim($_GET['hasta'] ?? '');
if ($desde !== '' && !fecha_valida($desde)) {
    $desde = '';
}
if ($hasta !== '' && !fecha_valida($hasta)) {
    $hasta = '';
}
$id_vendedor = filter_var($_GET['vendedor'] ?? '', FILTER_VALIDATE_INT);
$cliente = trim($_GET['cliente'] ?? '');

$where = "1 = 1";
$params = [];

if (!es_admin()) {
    $where .= " AND v.Id_usuario = ?";
    $params[] = $_SESSION['Id_usuario'];
} elseif ($id_vendedor) {
    $where .= " AND v.Id_usuario = ?";
    $params[] = $id_vendedor;
}
if ($cliente !== '') {
    $where .= " AND c.Nombre LIKE ?";
    $params[] = "%" . $cliente . "%";
}
if ($desde !== '') {
    $where .= " AND DATE(v.Fecha_venta) >= ?";
    $params[] = $desde;
}
if ($hasta !== '') {
    $where .= " AND DATE(v.Fecha_venta) <= ?";
    $params[] = $hasta;
}

$st = $conexion->prepare("SELECT COUNT(*) AS ventas, COALESCE(SUM(v.Total), 0) AS ingresos FROM ventas v JOIN clientes c ON c.Id_cliente = v.Id_cliente WHERE $where");
$st->execute($params);
$resumen = $st->fetch();

$ganancia = 0;
if (es_admin()) {
    $st = $conexion->prepare(
        "SELECT COALESCE(SUM(dv.Subtotal - dv.Costo_unitario * dv.Cantidad), 0)
         FROM detalle_ventas dv
         JOIN ventas v ON v.Id_venta = dv.Id_venta
         JOIN clientes c ON c.Id_cliente = v.Id_cliente
         WHERE $where"
    );
    $st->execute($params);
    $ganancia = (float)$st->fetchColumn();
}

$st = $conexion->prepare(
    "SELECT v.Id_venta, v.Fecha_venta, v.Total, u.Nombre_completo, c.Nombre AS Cliente
     FROM ventas v
     JOIN usuarios u ON u.Id_usuario = v.Id_usuario
     JOIN clientes c ON c.Id_cliente = v.Id_cliente
     WHERE $where
     ORDER BY v.Id_venta DESC
     LIMIT 100"
);
$st->execute($params);
$ventas = $st->fetchAll();

$detalles = [];
if (!empty($ventas)) {
    $ids = array_column($ventas, 'Id_venta');
    $marcadores = implode(',', array_fill(0, count($ids), '?'));
    $st = $conexion->prepare(
        "SELECT dv.Id_venta, dv.Cantidad, dv.Precio_unitario, dv.Costo_unitario, dv.Subtotal, p.Nombre
         FROM detalle_ventas dv
         JOIN productos p ON p.Id_producto = dv.Id_producto
         WHERE dv.Id_venta IN ($marcadores)
         ORDER BY p.Nombre"
    );
    $st->execute($ids);
    foreach ($st->fetchAll() as $d) {
        $detalles[$d['Id_venta']][] = $d;
    }
}

$lista_vendedores = [];
if (es_admin()) {
    $lista_vendedores = $conexion->query("SELECT Id_usuario, Nombre_completo FROM usuarios ORDER BY Nombre_completo")->fetchAll();
}

$promedio = (int)$resumen['ventas'] > 0 ? (float)$resumen['ingresos'] / (int)$resumen['ventas'] : 0;
$hay_filtro = ($desde !== '' || $hasta !== '' || $cliente !== '' || ($id_vendedor && es_admin()));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historial - Lazaroni</title>
    <link rel="stylesheet" href="estilo.css">
</head>
<body>

    <?php include "nav.php"; ?>

    <main class="contenedor">
        <?php if (es_admin()): ?>
            <h1 class="titulo-pagina">Historial de Ventas</h1>
            <p class="subtitulo">Todas las ventas registradas por los empleados</p>
        <?php else: ?>
            <h1 class="titulo-pagina">Mi Historial de Ventas</h1>
            <p class="subtitulo">Consulte las ventas que ha registrado</p>
        <?php endif; ?>

        <?php mostrar_flash(); ?>

        <section class="resumen">
            <div class="tarjeta-resumen">
                <div class="numero"><?php echo (int)$resumen['ventas']; ?></div>
                <div class="etiqueta">Ventas encontradas</div>
            </div>
            <div class="tarjeta-resumen">
                <div class="numero">Bs. <?php echo number_format($resumen['ingresos'], 2); ?></div>
                <div class="etiqueta"><?php echo es_admin() ? 'Ingresos' : 'Total vendido'; ?></div>
            </div>
            <?php if (es_admin()): ?>
                <div class="tarjeta-resumen verde">
                    <div class="numero">Bs. <?php echo number_format($ganancia, 2); ?></div>
                    <div class="etiqueta">Ganancia</div>
                </div>
            <?php else: ?>
                <div class="tarjeta-resumen">
                    <div class="numero">Bs. <?php echo number_format($promedio, 2); ?></div>
                    <div class="etiqueta">Promedio por venta</div>
                </div>
            <?php endif; ?>
        </section>

        <section class="panel">
            <h2 class="panel-titulo solo">Filtrar ventas</h2>

            <form method="GET" action="historial.php" class="filtros">
                <div class="campo">
                    <label for="desde">Desde</label>
                    <input type="date" id="desde" name="desde" value="<?php echo e($desde); ?>">
                </div>
                <div class="campo">
                    <label for="hasta">Hasta</label>
                    <input type="date" id="hasta" name="hasta" value="<?php echo e($hasta); ?>">
                </div>
                <div class="campo">
                    <label for="cliente">Cliente</label>
                    <input type="search" id="cliente" name="cliente" value="<?php echo e($cliente); ?>" placeholder="Nombre del cliente">
                </div>
                <?php if (es_admin()): ?>
                    <div class="campo">
                        <label for="vendedor">Vendedor</label>
                        <select id="vendedor" name="vendedor">
                            <option value="">Todos</option>
                            <?php foreach ($lista_vendedores as $lv): ?>
                                <option value="<?php echo (int)$lv['Id_usuario']; ?>" <?php echo ((int)$id_vendedor === (int)$lv['Id_usuario']) ? 'selected' : ''; ?>><?php echo e($lv['Nombre_completo']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                <button type="submit" class="btn btn-primario">Filtrar</button>
                <?php if ($hay_filtro): ?>
                    <a href="historial.php" class="btn btn-secundario">Limpiar</a>
                <?php endif; ?>
            </form>
        </section>

        <section class="panel">
            <h2 class="panel-titulo"><?php echo es_admin() ? 'Detalle de ventas' : 'Mis ventas'; ?></h2>
            <?php if ((int)$resumen['ventas'] > 100): ?>
                <p class="panel-subtitulo">Se muestran las 100 ventas más recientes. Use los filtros para acotar la búsqueda.</p>
            <?php else: ?>
                <p class="panel-subtitulo">Ordenadas de la más reciente a la más antigua</p>
            <?php endif; ?>

            <div class="tabla-scroll">
                <table class="tabla">
                    <thead>
                        <tr>
                            <th class="centro">N°</th>
                            <th>Fecha</th>
                            <th>Cliente</th>
                            <?php if (es_admin()): ?><th>Vendedor</th><?php endif; ?>
                            <th>Productos vendidos</th>
                            <th class="num">Total</th>
                            <?php if (es_admin()): ?><th class="num">Ganancia</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($ventas)): ?>
                            <tr><td colspan="<?php echo es_admin() ? 7 : 5; ?>" class="vacio">No se encontraron ventas.</td></tr>
                        <?php endif; ?>

                        <?php foreach ($ventas as $v):
                            $lineas = $detalles[$v['Id_venta']] ?? [];
                            $ganancia_venta = 0;
                            foreach ($lineas as $l) {
                                $ganancia_venta += (float)$l['Subtotal'] - (float)$l['Costo_unitario'] * (int)$l['Cantidad'];
                            }
                            $marca_tiempo = strtotime($v['Fecha_venta']);
                        ?>
                        <tr>
                            <td class="centro"><strong><?php echo (int)$v['Id_venta']; ?></strong></td>
                            <td>
                                <?php echo e(date('d/m/Y', $marca_tiempo)); ?>
                                <span class="sub"><?php echo e(date('H:i', $marca_tiempo)); ?> hs</span>
                            </td>
                            <td><?php echo e($v['Cliente']); ?></td>
                            <?php if (es_admin()): ?><td><?php echo e($v['Nombre_completo']); ?></td><?php endif; ?>
                            <td>
                                <div class="lineas">
                                    <?php foreach ($lineas as $l): ?>
                                        <div class="linea">
                                            <span class="cant"><?php echo (int)$l['Cantidad']; ?> ×</span>
                                            <span class="nom"><?php echo e($l['Nombre']); ?> <span class="sub">Bs. <?php echo number_format($l['Precio_unitario'], 2); ?> c/u</span></span>
                                            <span class="monto">Bs. <?php echo number_format($l['Subtotal'], 2); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                            <td class="num"><strong>Bs. <?php echo number_format($v['Total'], 2); ?></strong></td>
                            <?php if (es_admin()): ?><td class="num">Bs. <?php echo number_format($ganancia_venta, 2); ?></td><?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>

</body>
</html>
