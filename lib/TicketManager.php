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
     * Dibuja varios tickets de consumición en una rejilla (3 por fila) sobre páginas A4,
     * con líneas de corte entre ellos, para ahorrar papel frente a una página por ticket.
     */
    private static function pintarGridConsumiciones(\TCPDF $pdf, array $consumiciones, array $evento): void
    {
        if (empty($consumiciones)) return;

        $cols = 3;
        $rows = 4;
        $porPagina = $cols * $rows;
        $margenX = 8;
        $margenY = 8;
        $anchoUtil = 210 - 2 * $margenX;
        $altoUtil  = 297 - 2 * $margenY;
        $cellW = $anchoUtil / $cols;
        $cellH = $altoUtil / $rows;

        foreach (array_chunk($consumiciones, $porPagina) as $pagina) {
            $pdf->AddPage('P', 'A4');

            // Líneas de corte verticales
            $pdf->SetDrawColor(180, 180, 180);
            $pdf->SetLineStyle(['width' => 0.2, 'dash' => '2,2']);
            for ($c = 1; $c < $cols; $c++) {
                $x = $margenX + $c * $cellW;
                $pdf->Line($x, $margenY, $x, $margenY + $altoUtil);
            }
            // Líneas de corte horizontales
            for ($r = 1; $r < $rows; $r++) {
                $y = $margenY + $r * $cellH;
                $pdf->Line($margenX, $y, $margenX + $anchoUtil, $y);
            }
            $pdf->SetLineStyle(['width' => 0.2, 'dash' => 0]);

            foreach ($pagina as $i => $consumicion) {
                $col = $i % $cols;
                $row = intdiv($i, $cols);
                $x = $margenX + $col * $cellW;
                $y = $margenY + $row * $cellH;
                self::pintarCeldaConsumicion($pdf, $consumicion, $evento, $x, $y, $cellW, $cellH);
            }
        }
    }

    /**
     * Dibuja un único ticket de consumición dentro de una celda de la rejilla
     */
    private static function pintarCeldaConsumicion(\TCPDF $pdf, array $consumicion, array $evento, float $x, float $y, float $w, float $h): void
    {
        $pad = 4;
        $qrUrl = baseUrl() . '/qr-reader/validar-consumicion.php?t=' . urlencode($consumicion['qr_token']);
        $qrBase64 = self::generarQRImagenBase64($qrUrl);

        $pdf->SetXY($x + $pad, $y + $pad);
        $pdf->SetTextColor(99, 102, 241);
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->MultiCell($w - 2 * $pad, 4, strtoupper($evento['nombre']), 0, 'C');

        $qrSize = min($w, $h) * 0.45;
        $qrX = $x + ($w - $qrSize) / 2;
        $qrY = $y + $pad + 5;
        if ($qrBase64) {
            $qrTmp = tempnam(sys_get_temp_dir(), 'qr_') . '.png';
            file_put_contents($qrTmp, base64_decode($qrBase64));
            $pdf->Image($qrTmp, $qrX, $qrY, $qrSize, $qrSize, 'PNG');
            @unlink($qrTmp);
        }

        $nombreY = $qrY + $qrSize + 2;
        $pdf->SetTextColor(26, 26, 26);
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetXY($x + $pad, $nombreY);
        $pdf->MultiCell($w - 2 * $pad, 4, strtoupper($consumicion['producto_nombre']), 0, 'C');

        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->SetXY($x + $pad, $pdf->GetY() + 1);
        $pdf->Cell($w - 2 * $pad, 4, $consumicion['codigo_corto'], 0, 1, 'C');

        $pdf->SetFont('helvetica', 'I', 6);
        $pdf->SetTextColor(140, 140, 140);
        $pdf->SetXY($x + $pad, $y + $h - $pad - 4);
        $pdf->MultiCell($w - 2 * $pad, 3, 'Válido para una única consumición', 0, 'C');
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
        self::pintarGridConsumiciones($pdf, $consumiciones, $evento);

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
     * Genera el PDF de un lote de consumiciones vendidas en caja (sin pedido/inscripción).
     * Se descarga directamente, no se persiste su ruta en BD.
     */
    public static function generarPDFConsumicionesCaja(array $consumicionIds): string
    {
        if (empty($consumicionIds)) return '';
        $ph = implode(',', array_fill(0, count($consumicionIds), '?'));
        $st = db()->prepare("SELECT c.*, p.nombre as producto_nombre, c.evento_id
            FROM consumiciones c JOIN productos_consumicion p ON p.id=c.producto_id
            WHERE c.id IN ($ph) ORDER BY c.id ASC");
        $st->execute($consumicionIds);
        $consumiciones = $st->fetchAll();
        if (empty($consumiciones)) return '';

        $ev = db()->prepare('SELECT * FROM eventos WHERE id=?');
        $ev->execute([$consumiciones[0]['evento_id']]);
        $evento = $ev->fetch();

        $siteName = getSetting('site_name', 'Eventos');
        $pdf = self::nuevoPDF($siteName, 'Venta en caja - ' . $evento['nombre']);
        self::pintarGridConsumiciones($pdf, $consumiciones, $evento);
        return $pdf->Output('', 'S');
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
