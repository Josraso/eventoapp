# Changelog

Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/).
Versionado [SemVer](https://semver.org/lang/es/) (`MAJOR.MINOR.PATCH`).

## [1.7.0] - 2026-06-24

### Añadido
- En `mi-cuenta.php` las inscripciones del cliente se separan ahora en dos pestañas: "Mis inscripciones" (eventos actuales o futuros) y "Mis inscripciones pasadas" (eventos ya finalizados), cada una con su propio desglose de entradas/consumiciones, para no mezclar pedidos de eventos antiguos con los vigentes.
- Las entradas y consumiciones canjeadas/archivadas muestran ahora su código corto y un botón "Desmarcar" para revertirlas, tanto en el panel admin (`admin/inscripciones/detalle.php`, ventas en caja de `admin/consumiciones`) como en las vistas de portero y camarero del lector QR.

## [1.6.0] - 2026-06-24

### Añadido
- Las consumiciones de barra (con o sin entrada) ya no tienen tope de 50 unidades por producto.

### Corregido
- La app no fijaba ninguna zona horaria y PHP usaba UTC por defecto, así que la fecha límite de inscripción (y cualquier otra fecha) se cerraba 1-2 horas antes de la hora real introducida por el admin. Ahora toda la app (PHP y las consultas SQL con `NOW()`) usa hora de España (Europe/Madrid, con cambio de horario de verano automático).
- La compra de consumiciones sin entrada (`barra.php`) no comprobaba ninguna fecha y se podía hacer indefinidamente, incluso con el evento ya terminado. Ahora se permite mientras dure el evento (hasta el final del día de su última fecha) y se cierra al terminar, en vez de atarse a la fecha límite de inscripción de las entradas.
- El asunto de los emails de pedido (confirmación, pendiente de pago, aviso a admin) era genérico y no decía si el pedido era de entradas, consumiciones o ambas cosas. Ahora el asunto refleja el contenido real del pedido y siempre incluye el nombre del evento.

## [1.5.0] - 2026-06-23

### Añadido
- Soporte para varias fechas por evento (festivales/eventos de varios días): el panel admin permite añadir una o varias fechas, y la web pública muestra todas las fechas (o el rango) en la ficha y en el listado de eventos.
- La ficha pública del evento ahora muestra claramente la fecha límite de inscripción, distinguiéndola visualmente de la(s) fecha(s) del evento, y la marca en rojo si ya está cerrada.

### Corregido
- Un admin (no superadmin) podía ver, editar, desactivar y eliminar cualquier usuario registrado en el sistema desde `admin/usuarios`, incluidos los que nunca compraron en sus propios eventos. Ahora solo ve y gestiona usuarios con al menos un pedido en un evento suyo.
- Email de aviso de nuevo pedido al admin/superadmin propietario del evento: antes solo se enviaba si era necesario confirmar el pago, ahora se envía siempre al recibir un pedido (entradas y/o consumiciones), esté o no ya pagado.
- Porteros y camareros que trabajan eventos de varios organizadores ahora son visibles para todos los admins de esos eventos, no solo para quien los creó; al reasignar eventos solo se modifican las asignaciones propias, sin tocar las de otros admins.
- Error 404 al entrar directamente por el dominio raíz: ahora redirige a la web pública.

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
