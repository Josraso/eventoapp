<?php
require_once __DIR__ . '/db.php';

class TicketManager
{
    /**
     * Genera el token QR firmado con HMAC para una entrada
     */
    public static function generarQRToken(int $entradaId, int $eventoId, int $inscripcionId): array
    {
        $token = bin2hex(random_bytes(24)); // 48 chars único
        $payload = $entradaId . '|' . $eventoId . '|' . $inscripcionId . '|' . $token;
        $hash = hash_hmac('sha256', $payload, APP_SECRET);
        return ['token' => $token, 'hash' => $hash, 'payload' => $payload];
    }

    /**
     * Valida un QR token
     */
    public static function validarQRToken(string $token): ?array
    {
        $st = db()->prepare('SELECT e.*, i.numero_pedido, i.evento_id as ins_evento_id,
            ev.nombre as evento_nombre, ev.fecha_evento, ev.lugar, ev.campo_qr_extra
            FROM entradas e
            JOIN inscripciones i ON i.id = e.inscripcion_id
            JOIN eventos ev ON ev.id = e.evento_id
            WHERE e.qr_token = ?');
        $st->execute([$token]);
        $entrada = $st->fetch();
        if (!$entrada) return null;

        // Verificar HMAC
        $payload = $entrada['id'] . '|' . $entrada['evento_id'] . '|' . $entrada['inscripcion_id'] . '|' . $token;
        $expectedHash = hash_hmac('sha256', $payload, APP_SECRET);
        if (!hash_equals($expectedHash, $entrada['qr_hash'])) return null;

        return $entrada;
    }

    /**
     * Busca una entrada por su código corto (para introducción manual en el portero)
     */
    public static function validarCodigoCorto(string $codigo): ?array
    {
        $st = db()->prepare('SELECT e.*, i.numero_pedido, i.evento_id as ins_evento_id,
            ev.nombre as evento_nombre, ev.fecha_evento, ev.lugar, ev.campo_qr_extra
            FROM entradas e
            JOIN inscripciones i ON i.id = e.inscripcion_id
            JOIN eventos ev ON ev.id = e.evento_id
            WHERE e.codigo_corto = ?');
        $st->execute([strtoupper($codigo)]);
        return $st->fetch() ?: null;
    }

    /**
     * Genera un código corto único, fácil de escribir a mano (sin 0/O/1/I)
     */
    public static function generarCodigoCorto(): string
    {
        $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $codigo = '';
            for ($i = 0; $i < 6; $i++) {
                $codigo .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
            }
            $exists = db()->prepare('SELECT COUNT(*) FROM entradas WHERE codigo_corto=?');
            $exists->execute([$codigo]);
        } while ((int)$exists->fetchColumn() > 0);
        return $codigo;
    }

    /**
     * Genera un código corto único para consumiciones (tabla propia de codigo_corto)
     */
    public static function generarCodigoCortoConsumicion(): string
    {
        $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $codigo = '';
            for ($i = 0; $i < 6; $i++) {
                $codigo .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
            }
            $exists = db()->prepare('SELECT COUNT(*) FROM consumiciones WHERE codigo_corto=?');
            $exists->execute([$codigo]);
        } while ((int)$exists->fetchColumn() > 0);
        return $codigo;
    }

    /**
     * Crea N tickets de consumición (uno por unidad, no es saldo) para un producto.
     * Si $inscripcionId es null, es una venta de caja sin pedido asociado.
     */
    public static function crearConsumiciones(int $productoId, int $cantidad, ?int $inscripcionId, string $origen, ?string $nombreComprador = null, ?int $vendidoPor = null): array
    {
        $stP = db()->prepare('SELECT * FROM productos_consumicion WHERE id=?');
        $stP->execute([$productoId]);
        $producto = $stP->fetch();
        if (!$producto) throw new Exception('Producto de consumición no encontrado.');

        $st = db()->prepare('INSERT INTO consumiciones
            (evento_id, producto_id, inscripcion_id, nombre_comprador, origen, precio, qr_token, codigo_corto, vendido_por)
            VALUES (?,?,?,?,?,?,?,?,?)');

        $ids = [];
        for ($i = 0; $i < $cantidad; $i++) {
            $token = bin2hex(random_bytes(24));
            $codigo = self::generarCodigoCortoConsumicion();
            $st->execute([$producto['evento_id'], $productoId, $inscripcionId, $nombreComprador, $origen, $producto['precio'], $token, $codigo, $vendidoPor]);
            $ids[] = (int)db()->lastInsertId();
        }
        return $ids;
    }

    /**
     * Valida un QR token de consumición
     */
    public static function validarQRTokenConsumicion(string $token): ?array
    {
        $st = db()->prepare('SELECT c.*, p.nombre as producto_nombre, ev.nombre as evento_nombre
            FROM consumiciones c
            JOIN productos_consumicion p ON p.id = c.producto_id
            JOIN eventos ev ON ev.id = c.evento_id
            WHERE c.qr_token = ?');
        $st->execute([$token]);
        return $st->fetch() ?: null;
    }

    /**
     * Busca una consumición por su código corto (introducción manual)
     */
    public static function validarCodigoCortoConsumicion(string $codigo): ?array
    {
        $st = db()->prepare('SELECT c.*, p.nombre as producto_nombre, ev.nombre as evento_nombre
            FROM consumiciones c
            JOIN productos_consumicion p ON p.id = c.producto_id
            JOIN eventos ev ON ev.id = c.evento_id
            WHERE c.codigo_corto = ?');
        $st->execute([strtoupper($codigo)]);
        return $st->fetch() ?: null;
    }

    /**
     * Genera la imagen QR como PNG en base64
     */
    public static function generarQRImagenBase64(string $qrContent): string
    {
        $autoload = __DIR__ . '/../vendor/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
            try {
                $qrCode = new \Endroid\QrCode\QrCode(data: $qrContent, size: 300, margin: 10);
                $writer = new \Endroid\QrCode\Writer\PngWriter();
                $result = $writer->write($qrCode);
                return base64_encode($result->getString());
            } catch (\Throwable $e) {
                error_log('Error generando QR: ' . $e->getMessage());
            }
        }
        // Fallback: QR via API pública (solo desarrollo)
        return '';
    }

    /**
     * Crea un TCPDF nuevo con la configuración común (A5, sin cabecera/pie automáticos)
     */
    private static function nuevoPDF(string $siteName, string $titulo): \TCPDF
    {
        $autoload = __DIR__ . '/../vendor/autoload.php';
        if (!file_exists($autoload)) {
            throw new Exception('Composer autoload no encontrado. Ejecuta composer install.');
        }
        require_once $autoload;

        $pdf = new \TCPDF('P', 'mm', [148, 210], true, 'UTF-8', false); // A5
        $pdf->SetCreator($siteName);
        $pdf->SetAuthor($siteName);
        $pdf->SetTitle($titulo);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(true, 10);
        return $pdf;
    }

    /**
     * Genera el PDF de una entrada
     */
    public static function generarPDF(array $entrada, array $evento, array $inscripcion): string
    {
        $siteName = getSetting('site_name', 'Eventos');
        $pdf = self::nuevoPDF($siteName, 'Entrada - ' . $evento['nombre']);
        self::pintarPaginaEntrada($pdf, $entrada, $evento, $inscripcion);
        return $pdf->Output('', 'S');
    }

    /**
     * Dibuja la página de una entrada dentro de un PDF ya creado
     */
    private static function pintarPaginaEntrada(\TCPDF $pdf, array $entrada, array $evento, array $inscripcion): void
    {
        $siteName = getSetting('site_name', 'Eventos');

        // QR content = URL de validación
        $qrUrl = baseUrl() . '/qr-reader/validar.php?t=' . urlencode($entrada['qr_token']);
        $qrBase64 = self::generarQRImagenBase64($qrUrl);

        $nombreEvento = $evento['nombre'];
        $fechaEvento  = $evento['fecha_evento'] ? date('d/m/Y H:i', strtotime($evento['fecha_evento'])) : 'Por confirmar';
        $lugar        = $evento['lugar'] ?? '';
        $asistente    = $entrada['nombre_asistente'];
        $pedido       = $inscripcion['numero_pedido'];

        // Campos extra del asistente
        $camposExtra = [];
        if (!empty($entrada['campos_extra'])) {
            $camposExtra = is_string($entrada['campos_extra'])
                ? json_decode($entrada['campos_extra'], true) ?? []
                : $entrada['campos_extra'];
        }

        $pdf->AddPage();

        // Fondo cabecera
        $pdf->SetFillColor(26, 26, 26);
        $pdf->Rect(0, 0, 148, 40, 'F');

        // Logo / Nombre sitio
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->SetXY(10, 10);
        $pdf->Cell(128, 8, $siteName, 0, 1, 'L');

        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetXY(10, 20);
        $pdf->Cell(128, 6, 'ENTRADA', 0, 1, 'L');

        // Cuerpo
        $pdf->SetTextColor(26, 26, 26);
        $pdf->SetXY(10, 48);
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->MultiCell(128, 7, $nombreEvento, 0, 'L', false, 1);

        $pdf->SetFont('helvetica', '', 10);
        $y = $pdf->GetY() + 4;

        $campos = [
            ['Asistente', $asistente],
            ['Fecha', $fechaEvento],
        ];
        if ($lugar) $campos[] = ['Lugar', $lugar];
        $campos[] = ['Nº Pedido', $pedido];
        foreach ($camposExtra as $k => $v) {
            if ($v) $campos[] = [ucfirst(str_replace('_', ' ', $k)), $v];
        }

        $lineH = 5;
        foreach ($campos as [$label, $valor]) {
            $pdf->SetFont('helvetica', 'B', 9);
            $labelLines = $pdf->getNumLines(strtoupper((string)$label), 35);
            $pdf->SetFont('helvetica', '', 10);
            $valueLines = $pdf->getNumLines((string)$valor, 90);
            $rowH = max($labelLines, $valueLines) * $lineH;

            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->SetXY(10, $y);
            $pdf->MultiCell(35, $lineH, strtoupper((string)$label), 0, 'L', false, 0);
            $pdf->SetFont('helvetica', '', 10);
            $pdf->SetXY(45, $y);
            $pdf->MultiCell(90, $lineH, (string)$valor, 0, 'L', false, 0);

            $y += $rowH + 1;
        }

        // QR
        if ($qrBase64) {
            $qrTmp = tempnam(sys_get_temp_dir(), 'qr_') . '.png';
            file_put_contents($qrTmp, base64_decode($qrBase64));
            $pdf->SetXY(98, 48);
            $pdf->Image($qrTmp, 100, 50, 40, 40, 'PNG');
            @unlink($qrTmp);
        }

        // Código corto (alternativa al QR para introducción manual)
        if (!empty($entrada['codigo_corto'])) {
            $pdf->SetXY(10, $y + 4);
            $pdf->SetFont('helvetica', 'B', 10);
            $pdf->SetTextColor(26, 26, 26);
            $pdf->Cell(128, 6, 'CÓDIGO: ' . $entrada['codigo_corto'], 0, 1, 'C');
            $y += 6;
        }

        // Token legible
        $pdf->SetXY(10, $y + 4);
        $pdf->SetFont('helvetica', '', 7);
        $pdf->SetTextColor(150, 150, 150);
        $pdf->Cell(128, 4, 'Token: ' . $entrada['qr_token'], 0, 1, 'C');

        // Línea separadora
        $pdf->SetDrawColor(200, 200, 200);
        $pdf->Line(10, $pdf->GetY() + 3, 138, $pdf->GetY() + 3);

        $pdf->SetXY(10, $pdf->GetY() + 7);
        $pdf->SetFont('helvetica', 'I', 8);
        $pdf->SetTextColor(120, 120, 120);
        $pdf->MultiCell(128, 4, 'Esta entrada es personal e intransferible. El código QR solo puede usarse una vez.', 0, 'C');
    }

    /**
     * Dibuja la página de una consumición (ticket de barra) dentro de un PDF ya creado.
     * El nombre del producto se muestra grande al pie del QR para identificarlo de un vistazo.
     */
    private static function pintarPaginaConsumicion(\TCPDF $pdf, array $consumicion, array $producto, array $evento): void
    {
        $qrUrl = baseUrl() . '/qr-reader/validar-consumicion.php?t=' . urlencode($consumicion['qr_token']);
        $qrBase64 = self::generarQRImagenBase64($qrUrl);

        $pdf->AddPage();

        // Fondo cabecera
        $pdf->SetFillColor(99, 102, 241);
        $pdf->Rect(0, 0, 148, 40, 'F');

        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->SetXY(10, 10);
        $pdf->Cell(128, 8, $evento['nombre'], 0, 1, 'L');

        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetXY(10, 20);
        $pdf->Cell(128, 6, 'TICKET DE CONSUMICIÓN', 0, 1, 'L');

        // QR centrado
        if ($qrBase64) {
            $qrTmp = tempnam(sys_get_temp_dir(), 'qr_') . '.png';
            file_put_contents($qrTmp, base64_decode($qrBase64));
            $pdf->Image($qrTmp, 54, 50, 40, 40, 'PNG');
            @unlink($qrTmp);
        }

        // Nombre del producto, grande, al pie del QR
        $pdf->SetTextColor(26, 26, 26);
        $pdf->SetFont('helvetica', 'B', 22);
        $pdf->SetXY(10, 92);
        $pdf->MultiCell(128, 10, strtoupper($producto['nombre']), 0, 'C');

        $y = $pdf->GetY() + 4;
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->SetXY(10, $y);
        $pdf->Cell(128, 6, 'CÓDIGO: ' . $consumicion['codigo_corto'], 0, 1, 'C');

        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetTextColor(150, 150, 150);
        $pdf->SetXY(10, $pdf->GetY() + 2);
        $pdf->Cell(128, 4, 'Token: ' . $consumicion['qr_token'], 0, 1, 'C');

        $pdf->SetDrawColor(200, 200, 200);
        $pdf->Line(10, $pdf->GetY() + 3, 138, $pdf->GetY() + 3);

        $pdf->SetXY(10, $pdf->GetY() + 7);
        $pdf->SetFont('helvetica', 'I', 8);
        $pdf->SetTextColor(120, 120, 120);
        $pdf->MultiCell(128, 4, 'Ticket válido para una única consumición. No es recargable ni reutilizable.', 0, 'C');
    }

    /**
     * Guarda el PDF en disco y actualiza la entrada
     */
    public static function guardarPDF(int $entradaId, string $pdfContent): string
    {
        $dir = __DIR__ . '/../storage/entradas/';
        if (!is_dir($dir)) mkdir($dir, 0750, true);

        $filename = 'entrada_' . $entradaId . '_' . substr(md5($entradaId . time()), 0, 8) . '.pdf';
        $path = $dir . $filename;
        file_put_contents($path, $pdfContent);

        db()->prepare('UPDATE entradas SET pdf_path=? WHERE id=?')
           ->execute(['storage/entradas/' . $filename, $entradaId]);

        return $path;
    }

    /**
     * Genera un único PDF combinado con todas las entradas y consumiciones de un pedido,
     * lo guarda en disco y lo asocia a la inscripción. Devuelve array con un solo path
     * (mantiene la firma anterior para no tocar los puntos donde ya se llamaba).
     */
    public static function generarPDFsInscripcion(int $inscripcionId): array
    {
        $ins = db()->prepare('SELECT * FROM inscripciones WHERE id=?');
        $ins->execute([$inscripcionId]);
        $inscripcion = $ins->fetch();

        $ev = db()->prepare('SELECT * FROM eventos WHERE id=?');
        $ev->execute([$inscripcion['evento_id']]);
        $evento = $ev->fetch();

        $st = db()->prepare('SELECT * FROM entradas WHERE inscripcion_id=? ORDER BY es_titular DESC, id ASC');
        $st->execute([$inscripcionId]);
        $entradas = $st->fetchAll();

        $stC = db()->prepare('SELECT c.*, p.nombre as producto_nombre FROM consumiciones c
            JOIN productos_consumicion p ON p.id=c.producto_id WHERE c.inscripcion_id=? ORDER BY c.id ASC');
        $stC->execute([$inscripcionId]);
        $consumiciones = $stC->fetchAll();

        $siteName = getSetting('site_name', 'Eventos');
        $pdf = self::nuevoPDF($siteName, 'Pedido - ' . $evento['nombre']);

        foreach ($entradas as $entrada) {
            self::pintarPaginaEntrada($pdf, $entrada, $evento, $inscripcion);
        }
        foreach ($consumiciones as $consumicion) {
            self::pintarPaginaConsumicion($pdf, $consumicion, ['nombre' => $consumicion['producto_nombre']], $evento);
        }

        $path = self::guardarPDFPedido($inscripcionId, $pdf->Output('', 'S'));
        return [$path];
    }

    /**
     * Guarda el PDF combinado de un pedido en disco y lo asocia a la inscripción
     */
    public static function guardarPDFPedido(int $inscripcionId, string $pdfContent): string
    {
        $dir = __DIR__ . '/../storage/entradas/';
        if (!is_dir($dir)) mkdir($dir, 0750, true);

        $filename = 'pedido_' . $inscripcionId . '_' . substr(md5($inscripcionId . time()), 0, 8) . '.pdf';
        $path = $dir . $filename;
        file_put_contents($path, $pdfContent);

        db()->prepare('UPDATE inscripciones SET pdf_path=? WHERE id=?')
           ->execute(['storage/entradas/' . $filename, $inscripcionId]);

        return $path;
    }

    /**
     * Genera número de pedido único
     */
    public static function generarNumeroPedido(): string
    {
        do {
            $pedido = 'PED-' . strtoupper(substr(date('y'), -2)) . date('m') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $exists = db()->prepare('SELECT COUNT(*) FROM inscripciones WHERE numero_pedido=?');
            $exists->execute([$pedido]);
        } while ((int)$exists->fetchColumn() > 0);
        return $pedido;
    }
}
