<?php
$pagina_actual = basename($_SERVER['SCRIPT_NAME']);

$pestanas = [
    ['panel.php', 'Panel'],
    ['productos.php', 'Inventario'],
    ['ventas.php', 'Ventas'],
    ['historial.php', 'Historial']
];

if (es_admin()) {
    $pestanas[] = ['entradas.php', 'Entradas'];
    $pestanas[] = ['marcas_proveedores.php', 'Marcas y Proveedores'];
    $pestanas[] = ['usuarios.php', 'Usuarios'];
}
?>
<header class="topbar">
    <div class="topbar-inner">
        <a href="panel.php" class="brand">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true">
                <path d="M12 2 3 7v10l9 5 9-5V7z"></path>
                <path d="M3 7l9 5 9-5M12 12v10"></path>
            </svg>
            <span>Comercializadora Lazaroni</span>
        </a>

        <div class="usuario">
            <strong><?php echo e($_SESSION['Nombre_completo']); ?></strong>
            <span class="rol-badge"><?php echo e($_SESSION['Nombre_rol']); ?></span>
            <a href="logout.php" class="btn-salir">Cerrar sesión</a>
        </div>
    </div>

    <nav class="tabs-barra">
        <div class="tabs">
            <?php foreach ($pestanas as $p): ?>
                <a href="<?php echo e($p[0]); ?>" class="<?php echo $pagina_actual === $p[0] ? 'activa' : ''; ?>"><?php echo e($p[1]); ?></a>
            <?php endforeach; ?>
        </div>
    </nav>
</header>
