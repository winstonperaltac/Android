# Sistema de Préstamos (PHP + MySQL)

Aplicación web para administrar una financiera de préstamos: clientes, préstamos a **rédito** y **san**, cobros, índice de crédito, ganancias, reinversión, gastos, nómina y auditoría. Está hecha en PHP puro (sin frameworks ni Composer) para subirla a cualquier hosting compartido.

## Requisitos

- PHP 7.4 o superior (probado en 8.3) con las extensiones `pdo_mysql`, `gd` y `mbstring`
- MySQL 5.7+ o MariaDB 10.3+
- Apache con `.htaccess` (recomendado) o cualquier servidor que ejecute PHP

## Instalación

1. Suba todos los archivos a su hosting (por ejemplo a `public_html/prestamos`).
2. Cree una base de datos MySQL vacía desde el panel (cPanel → MySQL Databases).
3. Abra `https://su-dominio/prestamos/install/` y complete el formulario con los datos de la base de datos, el nombre de la empresa y el usuario administrador.
4. Al terminar, **elimine la carpeta `install/`** o restrinja su acceso.
5. Dé permisos de escritura a `uploads/` y `config/` (normalmente 755).

Si prefiere instalar a mano, copie `config/config.example.php` como `config/config.php`, importe `install/schema.sql` y cree el usuario administrador en la tabla `usuarios`, con la contraseña generada por `password_hash()` y el rol `admin`.

## Reglas de negocio

### Préstamo a rédito
- No tiene cuotas. Cada periodo (semana o quincena) genera un **rédito del 10 %** (configurable) sobre el **capital pendiente**.
- El cliente puede:
  - **Pagar solo el rédito**: el 100 % va a ganancias.
  - **Abonar a capital**: solo si paga el rédito del periodo en curso (y los atrasados). El sistema cobra primero ese rédito y luego el abono. Los réditos siguientes se recalculan sobre el nuevo capital.
  - **Saldar**: paga el capital más el rédito del periodo en curso.

### Préstamo san (cuotas)
- Cuotas semanales o quincenales.
- El interés es de **10 % mensual**, con un **mínimo de 30 %** si el préstamo dura menos de 3 meses.
- Si sobran semanas después de un mes completo, se cobran como **un mes completo**. Por ejemplo, 13 semanas son 3 meses y 1 semana, así que se cobran 4 meses (40 %).
- Un mes equivale a 4 semanas o a 2 quincenas (configurable).
- Si el cliente no puede pagar la cuota completa, paga un **rédito equivalente a la mitad de la cuota**. Ese rédito va 100 % a ganancias y la cuota pasa al final del plan.
- El interés de cada cuota se divide en **50 % ganancia y 50 % reinversión** al capital (porcentaje configurable).

### Índice de crédito (300–850, modelo tipo Equifax/FICO)

| Factor | Peso |
|---|---|
| Historial de pagos (puntualidad y días de atraso, ponderados por antigüedad) | 35 % |
| Endeudamiento (capital pendiente frente al monto prestado) | 30 % |
| Antigüedad crediticia | 15 % |
| Préstamos saldados y préstamos incobrables | 10 % |
| Préstamos activos simultáneos | 10 % |

Categorías: Excelente (800+), Muy bueno (740+), Bueno (670+), Regular (580+), Deficiente (<580). El índice aparece en la ficha del cliente con el desglose de cada factor. **Solo el administrador** puede eliminar registros viejos del historial para que el cliente mejore su índice. Esa eliminación queda auditada. Las cuotas vencidas sin pagar no se pueden borrar: el cliente tiene que pagarlas.

## Módulos

- **Usuarios y privilegios**: roles Administrador, Supervisor, Cajero y Consulta, con permisos individuales por usuario. El inicio de sesión se bloquea tras 5 intentos fallidos.
- **Clientes**: cédula, nombres, apellidos, foto, dirección, teléfonos, garante y su teléfono, e índice de crédito.
- **Préstamos**: resumen de activos e inactivos, plan de cuotas, registro de pagos, recibo imprimible y anulación de pagos (solo administrador).
- **Reportes**: préstamos activos con atrasos o sin atrasos en un rango de fechas.
- **Ganancias**: cada registro muestra de dónde viene la ganancia (rédito o interés) y enlaza al préstamo y al recibo.
- **Ingresos por periodo**: semana, quincena, mes o año.
- **Reinversión y capital**: crecimiento del negocio con interés reinvertido, ganancias reinvertidas, aportes y retiros.
- **Gastos operativos**: categorías y conceptos prefijados, con monto fijo o variable.
- **Empleados y nómina**: sueldos que se pagan de las ganancias.
- **Auditoría** (solo administrador): cada acción queda registrada con usuario, IP, fecha, y datos antes y después.
- **Configuración** (marca blanca): nombre de la empresa, logo, RNC, moneda y tasas, para revender el sistema como servicio.

## Seguridad

Consultas preparadas con PDO, contraseñas con `password_hash`, tokens CSRF en todos los formularios, sesiones con `httponly` y `samesite`, subida de imágenes validada (sin ejecución de PHP en `uploads/`), carpetas `includes/` y `config/` bloqueadas por `.htaccess`, y expiración de sesión por inactividad.
