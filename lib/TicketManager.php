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
     * Genera el PDF de una entrada
     */
    public static function generarPDF(array $entrada, array $evento, array $inscripcion): string
    {
        $autoload = __DIR__ . '/../vendor/autoload.php';
        if (!file_exists($autoload)) {
            throw new Exception('Composer autoload no encontrado. Ejecuta composer install.');
        }
        require_once $autoload;

        // QR content = URL de validación
        $qrUrl = baseUrl() . '/qr-reader/validar.php?t=' . urlencode($entrada['qr_token']);
        $qrBase64 = self::generarQRImagenBase64($qrUrl);

        $siteName   = getSetting('site_name', 'Eventos');
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

        // Usar TCPDF
        $pdf = new \TCPDF('P', 'mm', [148, 210], true, 'UTF-8', false); // A5
        $pdf->SetCreator($siteName);
        $pdf->SetAuthor($siteName);
        $pdf->SetTitle('Entrada - ' . $nombreEvento);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(true, 10);
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

        foreach ($campos as [$label, $valor]) {
            $pdf->SetXY(10, $y);
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->Cell(35, 6, strtoupper($label), 0, 0, 'L');
            $pdf->SetFont('helvetica', '', 10);
            $pdf->MultiCell(90, 6, $valor, 0, 'L', false, 1);
            $y = $pdf->GetY() + 1;
        }

        // QR
        if ($qrBase64) {
            $qrTmp = tempnam(sys_get_temp_dir(), 'qr_') . '.png';
            file_put_contents($qrTmp, base64_decode($qrBase64));
            $pdf->SetXY(98, 48);
            $pdf->Image($qrTmp, 100, 50, 40, 40, 'PNG');
            @unlink($qrTmp);
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

        return $pdf->Output('', 'S'); // Devuelve como string
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
     * Genera y guarda PDFs para todas las entradas de una inscripción
     * Devuelve array de paths
     */
    public static function generarPDFsInscripcion(int $inscripcionId): array
    {
        $st = db()->prepare('SELECT e.*, i.numero_pedido FROM entradas e JOIN inscripciones i ON i.id=e.inscripcion_id WHERE e.inscripcion_id=?');
        $st->execute([$inscripcionId]);
        $entradas = $st->fetchAll();

        $ins = db()->prepare('SELECT * FROM inscripciones WHERE id=?');
        $ins->execute([$inscripcionId]);
        $inscripcion = $ins->fetch();

        $ev = db()->prepare('SELECT * FROM eventos WHERE id=?');
        $ev->execute([$inscripcion['evento_id']]);
        $evento = $ev->fetch();

        $paths = [];
        foreach ($entradas as $entrada) {
            $pdfContent = self::generarPDF($entrada, $evento, $inscripcion);
            $paths[] = self::guardarPDF($entrada['id'], $pdfContent);
        }
        return $paths;
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
