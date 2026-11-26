DROP DATABASE IF EXISTS lazaronibd;
CREATE DATABASE lazaronibd CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE lazaronibd;

CREATE TABLE roles (
    Id_rol INT AUTO_INCREMENT PRIMARY KEY,
    Nombre_rol VARCHAR(50) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE usuarios (
    Id_usuario INT AUTO_INCREMENT PRIMARY KEY,
    Nombre_completo VARCHAR(100) NOT NULL,
    Correo VARCHAR(100) NOT NULL UNIQUE,
    Contrasena_hash VARCHAR(255) NOT NULL,
    Id_rol INT NOT NULL,
    Activo TINYINT(1) NOT NULL DEFAULT 1,
    Fecha_registro DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (Id_rol) REFERENCES roles(Id_rol)
) ENGINE=InnoDB;

CREATE TABLE clientes (
    Id_cliente INT AUTO_INCREMENT PRIMARY KEY,
    Nombre VARCHAR(100) NOT NULL UNIQUE,
    Telefono VARCHAR(20),
    Fecha_registro DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE proveedores (
    Id_proveedor INT AUTO_INCREMENT PRIMARY KEY,
    Nombre_empresa VARCHAR(100) NOT NULL,
    Contacto VARCHAR(100),
    Telefono VARCHAR(20),
    Activo TINYINT(1) NOT NULL DEFAULT 1,
    Fecha_registro DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE marcas (
    Id_marca INT AUTO_INCREMENT PRIMARY KEY,
    Nombre VARCHAR(80) NOT NULL UNIQUE,
    Activo TINYINT(1) NOT NULL DEFAULT 1,
    Fecha_registro DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE productos (
    Id_producto INT AUTO_INCREMENT PRIMARY KEY,
    Nombre VARCHAR(100) NOT NULL,
    Tipo_fideo VARCHAR(50) NOT NULL,
    Id_marca INT NOT NULL,
    Id_proveedor INT NULL,
    Precio_compra DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    Precio_venta DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    Stock INT NOT NULL DEFAULT 0,
    Stock_minimo INT NOT NULL DEFAULT 5,
    Activo TINYINT(1) NOT NULL DEFAULT 1,
    Fecha_registro DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (Id_marca) REFERENCES marcas(Id_marca),
    FOREIGN KEY (Id_proveedor) REFERENCES proveedores(Id_proveedor)
) ENGINE=InnoDB;

CREATE TABLE imagenes_productos (
    Id_producto INT PRIMARY KEY,
    Tipo VARCHAR(30) NOT NULL,
    Contenido MEDIUMBLOB NOT NULL,
    Fecha_actualizacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (Id_producto) REFERENCES productos(Id_producto) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE ventas (
    Id_venta INT AUTO_INCREMENT PRIMARY KEY,
    Fecha_venta DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    Id_usuario INT NOT NULL,
    Id_cliente INT NOT NULL,
    Total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    FOREIGN KEY (Id_usuario) REFERENCES usuarios(Id_usuario),
    FOREIGN KEY (Id_cliente) REFERENCES clientes(Id_cliente)
) ENGINE=InnoDB;

CREATE TABLE detalle_ventas (
    Id_detalle INT AUTO_INCREMENT PRIMARY KEY,
    Id_venta INT NOT NULL,
    Id_producto INT NOT NULL,
    Cantidad INT NOT NULL,
    Precio_unitario DECIMAL(10,2) NOT NULL,
    Costo_unitario DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    Subtotal DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (Id_venta) REFERENCES ventas(Id_venta) ON DELETE CASCADE,
    FOREIGN KEY (Id_producto) REFERENCES productos(Id_producto)
) ENGINE=InnoDB;

CREATE TABLE entradas_inventario (
    Id_entrada INT AUTO_INCREMENT PRIMARY KEY,
    Fecha_entrada DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    Id_proveedor INT NOT NULL,
    Id_usuario INT NOT NULL,
    Total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    Observacion VARCHAR(255),
    FOREIGN KEY (Id_proveedor) REFERENCES proveedores(Id_proveedor),
    FOREIGN KEY (Id_usuario) REFERENCES usuarios(Id_usuario)
) ENGINE=InnoDB;

CREATE TABLE detalle_entradas (
    Id_detalle INT AUTO_INCREMENT PRIMARY KEY,
    Id_entrada INT NOT NULL,
    Id_producto INT NOT NULL,
    Cantidad INT NOT NULL,
    Precio_compra DECIMAL(10,2) NOT NULL,
    Subtotal DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (Id_entrada) REFERENCES entradas_inventario(Id_entrada) ON DELETE CASCADE,
    FOREIGN KEY (Id_producto) REFERENCES productos(Id_producto)
) ENGINE=InnoDB;

INSERT INTO roles (Id_rol, Nombre_rol) VALUES
(1, 'Administrador'),
(2, 'Vendedor');

INSERT INTO usuarios (Nombre_completo, Correo, Contrasena_hash, Id_rol) VALUES
('Anghelo Caracara', 'admin@lazaroni.com', '$2y$10$BCOKZx.Z68iHhKSvxPWYHu5OaH.jwCvxN77tM2fFCDVgkhlw9Rlci', 1),
('Juan Vendedor', 'vendedor@lazaroni.com', '$2y$10$BCOKZx.Z68iHhKSvxPWYHu5OaH.jwCvxN77tM2fFCDVgkhlw9Rlci', 2);

INSERT INTO clientes (Nombre) VALUES ('Cliente general');

INSERT INTO proveedores (Nombre_empresa, Contacto, Telefono) VALUES
('Distribuidora Famosa SRL', 'Juan Perez', '70012345'),
('Industrias Alimenticias Bolivianas', 'Maria Gomez', '71154321');

INSERT INTO marcas (Nombre) VALUES
('La Suprema'),
('Aurora');

INSERT INTO productos (Nombre, Tipo_fideo, Id_marca, Id_proveedor, Precio_compra, Precio_venta, Stock, Stock_minimo) VALUES
('Fideo Codo 400g', 'Codo', 1, 1, 5.00, 7.50, 45, 10),
('Fideo Espagueti 500g', 'Espagueti', 2, 2, 6.00, 8.50, 8, 15);
