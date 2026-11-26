<?php
require_once "auth.php";
require_once "conexion.php";
requerir_login();

class VentaError extends Exception
{
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    csrf_validar();

    $items = json_decode($_POST['carrito'] ?? '[]', true);
    $cantidades = [];

    if (is_array($items) && count($items) > 0 && count($items) <= 100) {
        foreach ($items as $it) {
            $id = filter_var($it['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $cant = filter_var($it['cantidad'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);
            if ($id === false || $cant === false) {
                $cantidades = [];
                break;
            }
            $cantidades[$id] = ($cantidades[$id] ?? 0) + $cant;
        }
    }

    if (empty($cantidades)) {
        flash('error', 'El carrito está vacío o contiene datos no válidos.');
        header("Location: ventas.php");
        exit();
    }

    $nombre_cliente = trim($_POST['cliente'] ?? '');
    if ($nombre_cliente === '') {
        $nombre_cliente = 'Cliente general';
    }
    if (mb_strlen($nombre_cliente) > 100) {
        flash('error', 'El nombre del cliente no puede superar 100 caracteres.');
        header("Location: ventas.php");
        exit();
    }

    try {
        $conexion->beginTransaction();

        $ids = array_keys($cantidades);
        sort($ids);
        $marcadores = implode(',', array_fill(0, count($ids), '?'));

        $st = $conexion->prepare(
            "SELECT Id_producto, Nombre, Precio_venta, Precio_compra, Stock
             FROM productos
             WHERE Activo = 1 AND Id_producto IN ($marcadores)
             ORDER BY Id_producto
             FOR UPDATE"
        );
        $st->execute($ids);

        $filas = [];
        foreach ($st->fetchAll() as $f) {
            $filas[(int)$f['Id_producto']] = $f;
        }

        $total = 0;
        foreach ($ids as $id) {
            if (!isset($filas[$id])) {
                throw new VentaError("Un producto del carrito ya no está disponible.");
            }
            if ($cantidades[$id] > (int)$filas[$id]['Stock']) {
                throw new VentaError("Stock insuficiente para '" . $filas[$id]['Nombre'] . "'. Disponible: " . $filas[$id]['Stock'] . " unidades.");
            }
            $total += round((float)$filas[$id]['Precio_venta'] * $cantidades[$id], 2);
        }
        $total = round($total, 2);

        $st = $conexion->prepare("SELECT Id_cliente FROM clientes WHERE Nombre = ?");
        $st->execute([$nombre_cliente]);
        $cliente = $st->fetch();
        if ($cliente) {
            $id_cliente = (int)$cliente['Id_cliente'];
        } else {
            $conexion->prepare("INSERT INTO clientes (Nombre) VALUES (?)")->execute([$nombre_cliente]);
            $id_cliente = (int)$conexion->lastInsertId();
        }

        $st = $conexion->prepare("INSERT INTO ventas (Fecha_venta, Id_usuario, Id_cliente, Total) VALUES (NOW(), ?, ?, ?)");
        $st->execute([$_SESSION['Id_usuario'], $id_cliente, $total]);
        $id_venta = (int)$conexion->lastInsertId();

        $st_det = $conexion->prepare(
            "INSERT INTO detalle_ventas (Id_venta, Id_producto, Cantidad, Precio_unitario, Costo_unitario, Subtotal)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $st_stock = $conexion->prepare(
            "UPDATE productos SET Stock = Stock - ? WHERE Id_producto = ? AND Stock >= ?"
        );

        foreach ($ids as $id) {
            $cant = $cantidades[$id];
            $precio = $filas[$id]['Precio_venta'];
            $costo = $filas[$id]['Precio_compra'];
            $subtotal = round((float)$precio * $cant, 2);

            $st_det->execute([$id_venta, $id, $cant, $precio, $costo, $subtotal]);
            $st_stock->execute([$cant, $id, $cant]);

            if ($st_stock->rowCount() !== 1) {
                throw new VentaError("No se pudo descontar el stock de '" . $filas[$id]['Nombre'] . "'.");
            }
        }

        $conexion->commit();
        flash('exito', 'Venta #' . $id_venta . ' para ' . $nombre_cliente . ' registrada por Bs. ' . number_format($total, 2) . '. El stock fue actualizado.');
    } catch (VentaError $ex) {
        if ($conexion->inTransaction()) {
            $conexion->rollBack();
        }
        flash('error', $ex->getMessage());
    } catch (Exception $ex) {
        if ($conexion->inTransaction()) {
            $conexion->rollBack();
        }
        error_log("Error en ventas.php: " . $ex->getMessage());
        flash('error', 'No se pudo procesar la venta. Intente nuevamente.');
    }

    header("Location: ventas.php");
    exit();
}

$stmt = $conexion->query(
    "SELECT p.Id_producto, p.Nombre, m.Nombre AS Marca, p.Precio_venta, p.Stock,
            i.Fecha_actualizacion AS Fecha_imagen
     FROM productos p
     JOIN marcas m ON m.Id_marca = p.Id_marca
     LEFT JOIN imagenes_productos i ON i.Id_producto = p.Id_producto
     WHERE p.Activo = 1 AND p.Stock > 0
     ORDER BY p.Nombre ASC"
);
$lista = [];
$marcas_lista = [];
foreach ($stmt->fetchAll() as $f) {
    $lista[] = [
        'id' => (int)$f['Id_producto'],
        'nombre' => $f['Nombre'],
        'marca' => $f['Marca'],
        'precio' => (float)$f['Precio_venta'],
        'stock' => (int)$f['Stock'],
        'imagen' => imagen_src($f['Id_producto'], $f['Fecha_imagen'])
    ];
    $marcas_lista[$f['Marca']] = true;
}
$marcas_lista = array_keys($marcas_lista);
sort($marcas_lista);

$clientes = $conexion->query("SELECT Nombre FROM clientes ORDER BY Nombre")->fetchAll(PDO::FETCH_COLUMN);

if (es_admin()) {
    $stmt = $conexion->query(
        "SELECT COUNT(*) AS ventas, COALESCE(SUM(Total), 0) AS total
         FROM ventas
         WHERE Fecha_venta >= CURDATE()"
    );
} else {
    $stmt = $conexion->prepare(
        "SELECT COUNT(*) AS ventas, COALESCE(SUM(Total), 0) AS total
         FROM ventas
         WHERE Id_usuario = ? AND Fecha_venta >= CURDATE()"
    );
    $stmt->execute([$_SESSION['Id_usuario']]);
}
$hoy = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ventas - Lazaroni</title>
    <link rel="stylesheet" href="estilo.css">
</head>
<body>

    <?php include "nav.php"; ?>

    <main class="contenedor">
        <h1 class="titulo-pagina">Registro de Ventas</h1>
        <p class="subtitulo">Agregue productos al carrito y confirme la venta para descontar el stock</p>

        <?php mostrar_flash(); ?>

        <div class="banner-venta">
            <?php if (es_admin()): ?>
                Hoy el negocio lleva <strong><?php echo (int)$hoy['ventas']; ?></strong> ventas por un total de
            <?php else: ?>
                Hoy lleva <strong><?php echo (int)$hoy['ventas']; ?></strong> ventas registradas por un total de
            <?php endif; ?>
            <strong>Bs. <?php echo number_format($hoy['total'], 2); ?></strong>
        </div>

        <section class="panel">
            <h2 class="panel-titulo">Carrito de venta</h2>
            <p class="panel-subtitulo">Use los botones + y − para ajustar las cantidades</p>

            <div class="carrito-top">
                <div class="carrito-lista" id="carrito-lista"></div>

                <div class="carrito-resumen">
                    <form method="POST" action="ventas.php" id="form-venta">
                        <?php echo csrf_campo(); ?>
                        <input type="hidden" name="carrito" id="campo-carrito" value="[]">

                        <div class="campo">
                            <label for="cliente">Cliente</label>
                            <input type="text" id="cliente" name="cliente" list="lista-clientes" maxlength="100" placeholder="Cliente general" autocomplete="off">
                            <datalist id="lista-clientes">
                                <?php foreach ($clientes as $c): ?>
                                    <option value="<?php echo e($c); ?>"></option>
                                <?php endforeach; ?>
                            </datalist>
                        </div>

                        <div class="carrito-total">
                            <span>Total</span>
                            <span class="monto" id="carrito-total">Bs. 0.00</span>
                        </div>

                        <button type="submit" class="btn btn-primario btn-bloque" id="btn-confirmar" disabled>Confirmar venta</button>
                    </form>
                </div>
            </div>
        </section>

        <section class="panel">
            <h2 class="panel-titulo">Productos disponibles</h2>
            <p class="panel-subtitulo">Seleccione los productos que desea vender</p>

            <div class="barra-herramientas">
                <div class="buscador">
                    <input type="search" id="buscar-producto" placeholder="Buscar producto o marca">
                    <select id="filtro-marca">
                        <option value="">Todas las marcas</option>
                        <?php foreach ($marcas_lista as $m): ?>
                            <option value="<?php echo e($m); ?>"><?php echo e($m); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="btn btn-secundario" id="btn-buscar">Buscar</button>
                    <button type="button" class="btn btn-secundario" id="btn-todos">Mostrar todos</button>
                </div>
            </div>

            <div class="catalogo" id="catalogo"></div>
        </section>
    </main>

    <script>
        const productos = <?php echo json_encode($lista, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const mapa = {};
        productos.forEach(function (p) { mapa[p.id] = p; });
        const carrito = {};

        const catalogo = document.getElementById('catalogo');
        const listaCarrito = document.getElementById('carrito-lista');
        const totalEl = document.getElementById('carrito-total');
        const botonConfirmar = document.getElementById('btn-confirmar');
        const campoCarrito = document.getElementById('campo-carrito');
        const buscador = document.getElementById('buscar-producto');
        const filtroMarca = document.getElementById('filtro-marca');
        const formVenta = document.getElementById('form-venta');

        function dinero(n) {
            return 'Bs. ' + n.toFixed(2);
        }

        function el(etiqueta, clase, texto) {
            const nodo = document.createElement(etiqueta);
            if (clase) { nodo.className = clase; }
            if (texto !== undefined) { nodo.textContent = texto; }
            return nodo;
        }

        function imagenProducto(p, clase, claseVacia, textoVacio) {
            if (p.imagen) {
                const img = el('img', clase);
                img.src = p.imagen;
                img.alt = p.nombre;
                img.loading = 'lazy';
                return img;
            }
            return el('div', claseVacia, textoVacio);
        }

        function cambiar(id, delta) {
            const p = mapa[id];
            let nuevo = (carrito[id] || 0) + delta;
            if (nuevo > p.stock) { nuevo = p.stock; }
            if (nuevo <= 0) {
                delete carrito[id];
            } else {
                carrito[id] = nuevo;
            }
            dibujarCarrito();
            dibujarCatalogo();
        }

        function fijar(id, valor) {
            const p = mapa[id];
            let n = parseInt(valor, 10);
            if (isNaN(n) || n < 1) { n = 1; }
            if (n > p.stock) { n = p.stock; }
            carrito[id] = n;
            dibujarCarrito();
            dibujarCatalogo();
        }

        function quitar(id) {
            delete carrito[id];
            dibujarCarrito();
            dibujarCatalogo();
        }

        function dibujarCatalogo() {
            const texto = buscador.value.trim().toLowerCase();
            const marca = filtroMarca.value;
            catalogo.innerHTML = '';
            let mostrados = 0;

            productos.forEach(function (p) {
                if (texto && (p.nombre + ' ' + p.marca).toLowerCase().indexOf(texto) === -1) { return; }
                if (marca && p.marca !== marca) { return; }
                mostrados++;

                const enCarrito = carrito[p.id] || 0;
                const tarjeta = el('div', 'producto-card');

                const foto = el('div', p.imagen ? 'foto-producto' : 'foto-producto sin-foto');
                if (p.imagen) {
                    foto.appendChild(imagenProducto(p, '', '', ''));
                } else {
                    foto.textContent = 'Sin imagen';
                }
                tarjeta.appendChild(foto);

                const cuerpo = el('div', 'cuerpo');
                cuerpo.appendChild(el('div', 'nombre', p.nombre));
                cuerpo.appendChild(el('div', 'marca', p.marca));

                const filaPrecio = el('div', 'fila-precio');
                filaPrecio.appendChild(el('div', 'precio', dinero(p.precio)));
                filaPrecio.appendChild(el('span', 'chip-stock', p.stock + ' disp.' + (enCarrito ? ' · ' + enCarrito + ' en carrito' : '')));
                cuerpo.appendChild(filaPrecio);

                const boton = el('button', 'btn btn-secundario btn-chico', enCarrito >= p.stock ? 'Stock máximo' : 'Agregar al carrito');
                boton.type = 'button';
                boton.disabled = enCarrito >= p.stock;
                boton.addEventListener('click', function () { cambiar(p.id, 1); });
                cuerpo.appendChild(boton);

                tarjeta.appendChild(cuerpo);
                catalogo.appendChild(tarjeta);
            });

            if (mostrados === 0) {
                catalogo.appendChild(el('div', 'vacio', 'No hay productos disponibles con ese criterio.'));
            }
        }

        function dibujarCarrito() {
            listaCarrito.innerHTML = '';
            let total = 0;
            const ids = Object.keys(carrito);

            if (ids.length === 0) {
                listaCarrito.appendChild(el('div', 'vacio', 'El carrito está vacío. Agregue productos desde la lista de abajo.'));
            }

            ids.forEach(function (id) {
                const p = mapa[id];
                const cant = carrito[id];
                const subtotal = Math.round(p.precio * cant * 100) / 100;
                total += subtotal;

                const item = el('div', 'carrito-item');

                const superior = el('div', 'fila-superior');
                const info = el('div', 'producto-info');
                info.appendChild(imagenProducto(p, 'miniatura', 'sin-miniatura', 'Sin imagen'));
                const textos = el('div');
                textos.appendChild(el('div', 'nombre', p.nombre));
                textos.appendChild(el('div', 'unitario', p.marca + ' · ' + dinero(p.precio) + ' c/u'));
                info.appendChild(textos);
                superior.appendChild(info);
                const botonQuitar = el('button', 'quitar', '×');
                botonQuitar.type = 'button';
                botonQuitar.title = 'Quitar del carrito';
                botonQuitar.addEventListener('click', function () { quitar(p.id); });
                superior.appendChild(botonQuitar);
                item.appendChild(superior);

                const inferior = el('div', 'fila-inferior');
                const control = el('div', 'control-cantidad');
                const menos = el('button', '', '−');
                menos.type = 'button';
                menos.addEventListener('click', function () { cambiar(p.id, -1); });
                const cantidadEl = el('input', 'cantidad');
                cantidadEl.type = 'number';
                cantidadEl.min = '1';
                cantidadEl.max = String(p.stock);
                cantidadEl.step = '1';
                cantidadEl.value = String(cant);
                cantidadEl.setAttribute('aria-label', 'Cantidad');
                cantidadEl.addEventListener('change', function () { fijar(p.id, cantidadEl.value); });
                cantidadEl.addEventListener('keydown', function (ev) {
                    if (ev.key === 'Enter') {
                        ev.preventDefault();
                        cantidadEl.blur();
                    }
                });
                const mas = el('button', '', '+');
                mas.type = 'button';
                mas.disabled = cant >= p.stock;
                mas.addEventListener('click', function () { cambiar(p.id, 1); });
                control.appendChild(menos);
                control.appendChild(cantidadEl);
                control.appendChild(mas);
                inferior.appendChild(control);
                inferior.appendChild(el('div', 'subtotal', dinero(subtotal)));
                item.appendChild(inferior);

                listaCarrito.appendChild(item);
            });

            totalEl.textContent = dinero(Math.round(total * 100) / 100);
            botonConfirmar.disabled = ids.length === 0;
        }

        document.getElementById('btn-buscar').addEventListener('click', dibujarCatalogo);

        document.getElementById('btn-todos').addEventListener('click', function () {
            buscador.value = '';
            filtroMarca.value = '';
            dibujarCatalogo();
        });

        buscador.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter') {
                ev.preventDefault();
                dibujarCatalogo();
            }
        });

        filtroMarca.addEventListener('change', dibujarCatalogo);

        formVenta.addEventListener('submit', function (ev) {
            const ids = Object.keys(carrito);
            if (ids.length === 0) {
                ev.preventDefault();
                return;
            }
            campoCarrito.value = JSON.stringify(ids.map(function (id) {
                return { id: Number(id), cantidad: carrito[id] };
            }));
            botonConfirmar.disabled = true;
            botonConfirmar.textContent = 'Procesando...';
        });

        dibujarCatalogo();
        dibujarCarrito();
    </script>

</body>
</html>