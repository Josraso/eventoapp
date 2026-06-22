# Changelog

Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/).
Versionado [SemVer](https://semver.org/lang/es/) (`MAJOR.MINOR.PATCH`).

## [1.4.0] - 2026-06-22

### Añadido
- Sistema de descarga de PDF combinado por pedido (entradas + consumiciones), tanto en el área de cliente como en el panel admin.
- Vista de cliente (`mi-cuenta.php`) rediseñada con tabs globales "Entradas" / "Consumiciones": cada pedido se muestra en una tarjeta propia con borde marcado, número de pedido y fecha en negrita, indicador "Mixto" y enlace cruzado al otro tipo cuando el pedido combina entradas y consumiciones.
- Los pedidos completamente canjeados se ocultan tras un desplegable, igual que ya ocurría con cada entrada/consumición individual.
- Botón propio y visible "Comprar solo consumiciones (sin entrada)" en la ficha del evento, junto al precio y el resto de acciones.
- Desglose de importes por entradas vs. consumiciones en el listado admin de pedidos (resumen y por fila).
- Enlace de descarga de PDF añadido a la vista admin de consumiciones.

### Corregido
- Cookies de sesión de portero y camarero mezcladas entre sí: ahora cada rol usa su propia cookie (`eventoapp_sess`, `eventoapp_portero`, `eventoapp_camarero`).
- La detección de sesión de camarero comparaba solo el nombre de archivo, lo que hacía que `public/barra.php` (compra pública de consumiciones) se confundiera con `qr-reader/barra.php` (venta de camarero) y mandara al usuario a `mi-cuenta.php` en vez de a la compra. Ahora solo se considera camarero si el script está dentro de `/qr-reader/`.
- Descarga de PDF de pedidos compuestos solo por consumiciones (sin entradas), que antes daba 404 al buscar el pedido a través de la tabla `entradas`.
- Layout roto en móvil de las instrucciones de pago (Bizum/transferencia) en `pedido-pendiente.php`: el texto se mostraba en columnas en vez de apilado.
- Texto del email de confirmación de pedido ajustado según si el pedido incluye entradas, consumiciones o ambos.

## [1.3.0] - Sistema de consumiciones de barra

### Añadido
- Gestión de productos de barra (consumiciones) por evento desde el panel admin.
- Gestión de camareros y su asignación a eventos.
- Lector QR de barra para camareros, venta manual en caja y listado de consumiciones.
- Compra de consumiciones de barra junto con la entrada en el flujo de inscripción online.
- Vista admin de consumiciones con totales y listado de pedidos.
- Tickets de consumición agrupados en rejilla con líneas de corte para impresión.
- Pestañas separadas para entradas y consumiciones dentro de cada pedido (vista de cliente y panel admin).
- Filtro de canjeadas y visualización de QR en pantalla (sin descargar PDF).

## [1.2.0] - Refuerzo de inscripciones y multi-admin

### Añadido
- Aislamiento por admin de la gestión de eventos, pedidos, inscritos y porteros.
- Editor TinyMCE para la descripción de eventos y enlace directo a Google Maps.
- Edición completa de pedidos, entradas y usuarios desde el panel admin.
- Eliminación de pedidos, entradas y usuarios desde el panel admin.
- Transferencia automática de porteros al reasignar el propietario de un evento.

### Corregido
- Asignación de titular en inscripciones (ya no se asume que la persona 1 es siempre el comprador).
- Error 500 en el flujo de pago con Stripe y solapamiento de campos extra en el PDF de entrada.
- Pedidos de Stripe/Redsys quedan como `fallido` hasta que se confirma el pago, evitando entradas fantasma.

## [1.1.0] - Endurecimiento del panel admin

### Añadido
- División del menú de Inscripciones en "Pedidos" e "Inscritos".
- Migración automática de base de datos al actualizar.
- Exportación de pedidos e inscritos a Excel.
- Vendor (`composer install`) incluido en el repositorio para despliegues sin acceso a Composer.

### Corregido
- Conteo de inscritos en la página de inicio.
- Permisos de imágenes subidas y gestión de administradores.
- Páginas en blanco del panel admin y error al editar eventos.
- Escaneo de cámara QR endurecido, con entrada manual de código corto como alternativa.

## [1.0.0] - Lanzamiento inicial

### Añadido
- Gestión de eventos, inscripciones y entradas con código QR firmado (HMAC-SHA256).
- Pagos múltiples: Stripe, Redsys, Bizum y transferencia (confirmación manual para los dos últimos).
- Roles: superadmin, admin y portero, cada uno con su propia sesión.
- Lector QR para porteros y envío de entradas por email con adjuntos PDF.
- Instalador web y protección de carpetas sensibles (`storage/`, `logs/`, `install/`).
