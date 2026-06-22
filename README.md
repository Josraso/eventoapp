# EventoApp

Plataforma de gestión de eventos con inscripciones, pagos múltiples, entradas con QR y venta de consumiciones de barra.

Ver [CHANGELOG.md](CHANGELOG.md) para el histórico de versiones.

## Requisitos

- PHP 7.4+ (recomendado 8.1+)
- MySQL 5.7+ / MariaDB 10.3+
- Composer
- Extensiones PHP: `pdo_mysql`, `openssl`, `mbstring`, `gd`
- Servidor web: Apache (con mod_rewrite) o Nginx

## Instalación rápida

```bash
# 1. Subir los archivos al servidor

# 2. Instalar dependencias PHP
composer install

# 3. Dar permisos de escritura
chmod -R 775 storage/ logs/

# 4. Acceder al instalador web
https://tudominio.com/install/
```

## Estructura

```
eventoapp/
├── config.php          ← Generado por el instalador (no subir a git)
├── lib/                ← Librerías PHP (db, auth, mailer, qr, redsys)
├── public/             ← Área pública (index, evento, login, cuenta...)
├── admin/              ← Panel de administración
│   ├── eventos/        ← CRUD eventos
│   ├── inscripciones/  ← Listado, detalle, confirmar pagos, exportar
│   ├── usuarios/       ← Gestión usuarios registrados
│   ├── porteros/       ← Gestión porteros y camareros, asignación a eventos
│   ├── consumiciones/  ← Productos de barra por evento, listado de pedidos
│   └── ajustes/        ← SMTP, Stripe, Redsys, Bizum, reCAPTCHA
├── pago/               ← Procesadores de pago (Stripe, Redsys)
├── qr-reader/          ← Lector QR para porteros y venta de barra para camareros
├── storage/            ← PDFs generados, imágenes (protegido)
├── logs/               ← Logs de Redsys y QR (protegido)
├── install/            ← Instalador (eliminar tras instalar)
└── vendor/             ← Dependencias Composer (no subir a git)
```

## Métodos de pago

- **Stripe** — Tarjeta de crédito/débito, confirmación automática
- **Redsys** — TPV bancario, confirmación automática via notify
- **Bizum** — Manual, el admin confirma desde el panel
- **Transferencia** — Manual, el admin confirma desde el panel

Cada evento puede activar métodos de pago distintos para entradas y para consumiciones de barra.

## Consumiciones de barra

- El admin define productos de barra (nombre, precio) por evento.
- El cliente puede añadir consumiciones al comprar su entrada, o comprarlas solas sin entrada desde `public/barra.php`.
- Los camareros venden consumiciones en caja y las canjean con el lector QR (`/qr-reader/`), de forma independiente al lector de porteros.
- Cada pedido genera un único PDF combinado con entradas y/o consumiciones, descargable desde `mi-cuenta.php` y desde el panel admin.

## Roles

| Rol | Acceso |
|-----|--------|
| `superadmin` | Todo, incluyendo ajustes y eliminar |
| `admin` | Eventos, inscripciones, usuarios, porteros, camareros |
| `portero` | Solo lector QR de entradas (`/qr-reader/`) |
| `camarero` | Solo venta y canje de consumiciones de barra (`/qr-reader/`) |

## Seguridad post-instalación

1. Elimina o protege el directorio `install/`
2. Asegúrate de que `.htaccess` esté activo (`AllowOverride All`)
3. `storage/` y `logs/` bloqueados a acceso directo
4. `config.php` bloqueado por `.htaccess`
5. Los QR están firmados con HMAC-SHA256

## Nginx (configuración básica)

```nginx
location /storage { deny all; }
location /logs { deny all; }
location /lib { deny all; }
location /vendor { deny all; }
location /install { deny all; }
```

## Dependencias incluidas via Composer

- `endroid/qr-code` — Generación de QR
- `tecnickcom/tcpdf` — Generación de PDF
- `phpmailer/phpmailer` — Envío de email SMTP
- `stripe/stripe-php` — SDK de Stripe (si se usa Stripe)
