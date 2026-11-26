<?php
require_once "auth.php";
require_once "conexion.php";
requerir_login();

$periodos = [
    'hoy' => ['Hoy', "v.Fecha_venta >= CURDATE()"],
    'semana' => ['Últimos 7 días', "v.Fecha_venta >= CURDATE() - INTERVAL 6 DAY"],
    'mes' => ['Este mes', "v.Fecha_venta >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"]
];

$p = $_GET['p'] ?? 'hoy';
if (!isset($periodos[$p])) {
    $p = 'hoy';
}
$nombre_periodo = $periodos[$p][0];
$cond = $periodos[$p][1];

$id_vendedor = 0;
$lista_vendedores = [];
if (es_admin()) {
    $id_vendedor = (int)filter_var($_GET['vendedor'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'default' => 0]]);
    $lista_vendedores = $conexion->query("SELECT Id_usuario, Nombre_completo FROM usuarios ORDER BY Nombre_completo")->fetchAll();
    if ($id_vendedor) {
        $cond .= " AND v.Id_usuario = " . $id_vendedor;
    }
}
$extra_dia = $id_vendedor ? " AND Id_usuario = " . $id_vendedor : "";
$enlace_vend = $id_vendedor ? "&vendedor=" . $id_vendedor : "";

if (!es_admin()) {
    $st = $conexion->prepare(
        "SELECT
            COALESCE(SUM(CASE WHEN Fecha_venta >= CURDATE() THEN Total END), 0) AS hoy_total,
            COUNT(CASE WHEN Fecha_venta >= CURDATE() THEN 1 END) AS hoy_n,
            COALESCE(SUM(CASE WHEN Fecha_venta >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN Total END), 0) AS mes_total,
            COUNT(CASE WHEN Fecha_venta >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN 1 END) AS mes_n,
            COALESCE(SUM(Total), 0) AS todo_total,
            COUNT(*) AS todo_n
         FROM ventas
         WHERE Id_usuario = ?"
    );
    $st->execute([$_SESSION['Id_usuario']]);
    $mis = $st->fetch();
} else {
    $resumen = $conexion->query(
        "SELECT COUNT(*) AS ventas, COALESCE(SUM(v.Total), 0) AS ingresos
         FROM ventas v
         WHERE $cond"
    )->fetch();

    $detalle = $conexion->query(
        "SELECT COALESCE(SUM(dv.Cantidad), 0) AS unidades,
                COALESCE(SUM(dv.Subtotal - dv.Costo_unitario * dv.Cantidad), 0) AS ganancia
         FROM detalle_ventas dv
         JOIN ventas v ON v.Id_venta = dv.Id_venta
         WHERE $cond"
    )->fetch();

    $vendedores = $conexion->query(
        "SELECT u.Nombre_completo AS nombre, COUNT(*) AS cantidad, SUM(v.Total) AS total
         FROM ventas v
         JOIN usuarios u ON u.Id_usuario = v.Id_usuario
         WHERE $cond
         GROUP BY v.Id_usuario, u.Nombre_completo
         ORDER BY total DESC"
    )->fetchAll();

    $max_vendedor = 0;
    foreach ($vendedores as $v) {
        if ((float)$v['total'] > $max_vendedor) {
            $max_vendedor = (float)$v['total'];
        }
    }

    $top = $conexion->query(
        "SELECT pr.Nombre, SUM(dv.Cantidad) AS unidades, SUM(dv.Subtotal) AS total
         FROM detalle_ventas dv
         JOIN ventas v ON v.Id_venta = dv.Id_venta
         JOIN productos pr ON pr.Id_producto = dv.Id_producto
         WHERE $cond
         GROUP BY pr.Id_producto, pr.Nombre
         ORDER BY unidades DESC
         LIMIT 5"
    )->fetchAll();

    $hoy_fecha = $conexion->query("SELECT CURDATE()")->fetchColumn();
    $filas = $conexion->query(
        "SELECT DATE(Fecha_venta) AS dia, SUM(Total) AS total
         FROM ventas
         WHERE Fecha_venta >= CURDATE() - INTERVAL 6 DAY$extra_dia
         GROUP BY DATE(Fecha_venta)"
    )->fetchAll();

    $por_dia = [];
    foreach ($filas as $f) {
        $por_dia[$f['dia']] = (float)$f['total'];
    }

    $nombres_dia = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
    $dias = [];
    $max_dia = 0;
    for ($i = 6; $i >= 0; $i--) {
        $d = new DateTime($hoy_fecha);
        $d->modify("-$i day");
        $total_dia = $por_dia[$d->format('Y-m-d')] ?? 0;
        if ($total_dia > $max_dia) {
            $max_dia = $total_dia;
        }
        $dias[] = [
            'etiqueta' => $nombres_dia[(int)$d->format('w')] . ' ' . $d->format('d'),
            'total' => $total_dia
        ];
    }

    $recientes = $conexion->query(
        "SELECT v.Id_venta, v.Fecha_venta, v.Total, u.Nombre_completo AS vendedor, c.Nombre AS cliente
         FROM ventas v
         JOIN usuarios u ON u.Id_usuario = v.Id_usuario
         JOIN clientes c ON c.Id_cliente = v.Id_cliente
         WHERE $cond
         ORDER BY v.Id_venta DESC
         LIMIT 5"
    )->fetchAll();

    $criticos = (int)$conexion->query(
        "SELECT COUNT(*) FROM productos WHERE Activo = 1 AND Stock <= Stock_minimo"
    )->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel - Lazaroni</title>
    <link rel="stylesheet" href="estilo.css">
</head>
<body>

    <?php include "nav.php"; ?>

    <main class="contenedor">
        <?php mostrar_flash(); ?>

        <?php if (!es_admin()): ?>

            <h1 class="titulo-pagina">Mi Panel de Ventas</h1>
            <p class="subtitulo">Bienvenido, <?php echo e($_SESSION['Nombre_completo']); ?>. Este es el resumen de sus ventas.</p>

            <section class="resumen">
                <div class="tarjeta-resumen">
                    <div class="numero">Bs. <?php echo number_format($mis['hoy_total'], 2); ?></div>
                    <div class="etiqueta">Ventas de hoy</div>
                    <div class="detalle"><?php echo (int)$mis['hoy_n']; ?> ventas registradas</div>
                </div>
                <div class="tarjeta-resumen">
                    <div class="numero">Bs. <?php echo number_format($mis['mes_total'], 2); ?></div>
                    <div class="etiqueta">Ventas de este mes</div>
                    <div class="detalle"><?php echo (int)$mis['mes_n']; ?> ventas registradas</div>
                </div>
                <div class="tarjeta-resumen verde">
                    <div class="numero">Bs. <?php echo number_format($mis['todo_total'], 2); ?></div>
                    <div class="etiqueta">Total acumulado</div>
                    <div class="detalle"><?php echo (int)$mis['todo_n']; ?> ventas en total</div>
                </div>
            </section>

            <div class="pills">
                <a href="ventas.php" class="btn btn-secundario">Registrar una venta</a>
                <a href="historial.php" class="btn btn-secundario">Ver mi historial</a>
            </div>

        <?php else: ?>

            <h1 class="titulo-pagina">Panel de Control</h1>
            <p class="subtitulo">Resumen de ventas y ganancias de la comercializadora</p>

            <form method="GET" action="panel.php" class="filtros">
                <div class="campo">
                    <label for="p">Periodo</label>
                    <select id="p" name="p">
                        <?php foreach ($periodos as $clave => $info): ?>
                            <option value="<?php echo e($clave); ?>" <?php echo $p === $clave ? 'selected' : ''; ?>><?php echo e($info[0]); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="campo">
                    <label for="vendedor">Vendedor</label>
                    <select id="vendedor" name="vendedor">
                        <option value="">Todos los vendedores</option>
                        <?php foreach ($lista_vendedores as $lv): ?>
                            <option value="<?php echo (int)$lv['Id_usuario']; ?>" <?php echo $id_vendedor === (int)$lv['Id_usuario'] ? 'selected' : ''; ?>><?php echo e($lv['Nombre_completo']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primario">Aplicar</button>
                <?php if ($id_vendedor || $p !== 'hoy'): ?>
                    <a href="panel.php" class="btn btn-secundario">Limpiar</a>
                <?php endif; ?>
            </form>

            <section class="resumen cuatro">
                <div class="tarjeta-resumen">
                    <div class="numero">Bs. <?php echo number_format($resumen['ingresos'], 2); ?></div>
                    <div class="etiqueta">Ingresos</div>
                    <div class="detalle"><?php echo e($nombre_periodo); ?></div>
                </div>
                <div class="tarjeta-resumen verde">
                    <div class="numero">Bs. <?php echo number_format($detalle['ganancia'], 2); ?></div>
                    <div class="etiqueta">Ganancia</div>
                    <div class="detalle">Ingresos menos costo de compra</div>
                </div>
                <div class="tarjeta-resumen">
                    <div class="numero"><?php echo (int)$resumen['ventas']; ?></div>
                    <div class="etiqueta">Ventas realizadas</div>
                    <div class="detalle"><?php echo e($nombre_periodo); ?></div>
                </div>
                <div class="tarjeta-resumen">
                    <div class="numero"><?php echo number_format((int)$detalle['unidades']); ?></div>
                    <div class="etiqueta">Unidades vendidas</div>
                    <div class="detalle"><?php echo e($nombre_periodo); ?></div>
                </div>
            </section>

            <?php if ($criticos > 0): ?>
                <div class="aviso-rol">
                    Hay <strong><?php echo $criticos; ?></strong> producto(s) con stock crítico.
                    <a href="productos.php?critico=1"><strong>Revisar inventario</strong></a>
                </div>
            <?php endif; ?>

            <section class="graficos">
                <div class="panel">
                    <h2 class="panel-titulo">Ventas por vendedor</h2>
                    <p class="panel-subtitulo"><?php echo e($nombre_periodo); ?></p>

                    <?php if (empty($vendedores)): ?>
                        <div class="vacio">No hay ventas en este periodo.</div>
                    <?php endif; ?>

                    <?php foreach ($vendedores as $v):
                        $ancho = $max_vendedor > 0 ? round((float)$v['total'] / $max_vendedor * 100) : 0;
                    ?>
                        <div class="barra-h-fila">
                            <div class="barra-h-nombre"><?php echo e($v['nombre']); ?></div>
                            <div class="barra-h-pista"><div class="barra-h-valor" style="width: <?php echo (int)$ancho; ?>%"></div></div>
                            <div class="barra-h-monto">Bs. <?php echo number_format($v['total'], 2); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="panel">
                    <h2 class="panel-titulo">Ingresos por día</h2>
                    <p class="panel-subtitulo">Últimos 7 días</p>

                    <div class="barra-v-grafico">
                        <?php foreach ($dias as $d):
                            $alto = $max_dia > 0 ? round($d['total'] / $max_dia * 150) : 0;
                        ?>
                            <div class="barra-v-col">
                                <div class="barra-v-monto"><?php echo $d['total'] > 0 ? number_format($d['total'], 0) : ''; ?></div>
                                <div class="barra-v-barra" style="height: <?php echo (int)$alto; ?>px"></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="barra-v-etiquetas">
                        <?php foreach ($dias as $d): ?>
                            <span><?php echo e($d['etiqueta']); ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <section class="panel">
                <h2 class="panel-titulo">Productos más vendidos</h2>
                <p class="panel-subtitulo"><?php echo e($nombre_periodo); ?></p>

                <div class="tabla-scroll">
                    <table class="tabla">
                        <thead>
                            <tr>
                                <th>Producto</th>
                                <th class="centro">Unidades vendidas</th>
                                <th class="num">Ingresos</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($top)): ?>
                                <tr><td colspan="3" class="vacio">No hay ventas en este periodo.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($top as $t): ?>
                                <tr>
                                    <td><strong><?php echo e($t['Nombre']); ?></strong></td>
                                    <td class="centro"><?php echo (int)$t['unidades']; ?></td>
                                    <td class="num">Bs. <?php echo number_format($t['total'], 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="panel">
                <h2 class="panel-titulo">Últimas ventas</h2>
                <p class="panel-subtitulo">Las 5 más recientes del filtro seleccionado</p>

                <div class="tabla-scroll">
                    <table class="tabla">
                        <thead>
                            <tr>
                                <th class="centro">N°</th>
                                <th>Fecha</th>
                                <th>Cliente</th>
                                <th>Vendedor</th>
                                <th class="num">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recientes)): ?>
                                <tr><td colspan="5" class="vacio">No hay ventas en este periodo.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($recientes as $r): $t = strtotime($r['Fecha_venta']); ?>
                                <tr>
                                    <td class="centro"><strong><?php echo (int)$r['Id_venta']; ?></strong></td>
                                    <td><?php echo e(date('d/m/Y', $t)); ?> <span class="sub"><?php echo e(date('H:i', $t)); ?> hs</span></td>
                                    <td><?php echo e($r['cliente']); ?></td>
                                    <td><?php echo e($r['vendedor']); ?></td>
                                    <td class="num"><strong>Bs. <?php echo number_format($r['Total'], 2); ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

        <?php endif; ?>
    </main>

</body>
</html>
