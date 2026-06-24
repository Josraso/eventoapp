<?php
require_once __DIR__ . '/db.php';

class Mailer
{
    private string $method;
    private string $fromEmail;
    private string $fromName;
    private array  $smtp;

    public function __construct()
    {
        $this->method    = getSetting('mail_method', 'sendmail');
        $this->fromEmail = getSetting('mail_from_email', '');
        $this->fromName  = getSetting('mail_from_name', getSetting('site_name', 'Eventos'));
        $this->smtp = [
            'host'   => getSetting('smtp_host'),
            'port'   => (int)getSetting('smtp_port', '587'),
            'user'   => getSetting('smtp_user'),
            'pass'   => getSetting('smtp_pass'),
            'secure' => getSetting('smtp_secure', 'tls'),
        ];
    }

    public function send(string $to, string $toName, string $subject, string $bodyHtml, array $attachments = []): array
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL))
            return ['ok' => false, 'error' => 'Email destino no válido: ' . $to];
        if (!$this->fromEmail)
            return ['ok' => false, 'error' => 'Configura el email remitente en Ajustes'];

        // Si hay adjuntos, usamos PHPMailer siempre
        if (!empty($attachments) || $this->method === 'smtp') {
            return $this->sendPHPMailer($to, $toName, $subject, $bodyHtml, $attachments);
        }
        return $this->sendNative($to, $toName, $subject, $bodyHtml);
    }

    private function sendPHPMailer(string $to, string $toName, string $subject, string $bodyHtml, array $attachments = []): array
    {
        $autoload = __DIR__ . '/../vendor/autoload.php';
        if (!file_exists($autoload))
            return ['ok' => false, 'error' => 'PHPMailer no instalado (ejecuta composer install)'];

        require_once $autoload;

        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

            if ($this->method === 'smtp' && $this->smtp['host']) {
                $mail->isSMTP();
                $mail->Host       = $this->smtp['host'];
                $mail->Port       = $this->smtp['port'];
                $mail->SMTPAuth   = true;
                $mail->Username   = $this->smtp['user'];
                $mail->Password   = $this->smtp['pass'];
                $mail->SMTPSecure = $this->smtp['secure'] === 'ssl' ? 'ssl' : 'tls';
            } else {
                $mail->isMail(); // usa mail() de PHP, no requiere el binario sendmail
            }

            $mail->CharSet = 'UTF-8';
            $mail->setFrom($this->fromEmail, $this->fromName);
            $mail->addAddress($to, $toName);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $bodyHtml;
            $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $bodyHtml));

            foreach ($attachments as $att) {
                if (isset($att['path']) && file_exists($att['path'])) {
                    $mail->addAttachment($att['path'], $att['name'] ?? basename($att['path']));
                } elseif (isset($att['content'])) {
                    $mail->addStringAttachment($att['content'], $att['name'] ?? 'adjunto.pdf', 'base64', 'application/pdf');
                }
            }

            $mail->send();
            return ['ok' => true, 'error' => ''];
        } catch (\Exception $e) {
            // Si falla SMTP o el binario de sendmail, probamos con mail() nativo.
            // Sin adjuntos no hay problema; con adjuntos se pierde el PDF en el fallback,
            // pero es preferible avisar al usuario a que el correo no llegue nunca.
            $res = $this->sendNative($to, $toName, $subject, $bodyHtml);
            if (!$res['ok'])
                return ['ok' => false, 'error' => $this->method . ': ' . $e->getMessage() . ' | mail(): ' . $res['error']];
            if (!empty($attachments))
                return ['ok' => true, 'error' => '', 'warning' => 'Enviado sin adjunto: ' . $e->getMessage()];
            return $res;
        }
    }

    private function sendNative(string $to, string $toName, string $subject, string $bodyHtml): array
    {
        if (!function_exists('mail'))
            return ['ok' => false, 'error' => 'La función mail() no existe en este servidor'];

        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: =?UTF-8?B?" . base64_encode($this->fromName) . "?= <{$this->fromEmail}>\r\n";
        $headers .= "Reply-To: {$this->fromEmail}\r\n";

        $subj = '=?UTF-8?B?' . base64_encode($subject) . '?=';

        set_error_handler(function() {});
        $ok = mail($to, $subj, $bodyHtml, $headers);
        restore_error_handler();

        if (!$ok) {
            $err = error_get_last();
            return ['ok' => false, 'error' => $err['message'] ?? 'mail() devolvió false'];
        }
        return ['ok' => true, 'error' => ''];
    }

    // ── PLANTILLAS ────────────────────────────────────────────────────────────

    public static function tplBase(string $titulo, string $contenido, string $pie = ''): string
    {
        $siteName = getSetting('site_name', 'Eventos');
        $footer   = $pie ?: getSetting('mail_footer', '');
        return '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">
<style>
body{font-family:Arial,sans-serif;background:#f5f5f5;margin:0;padding:20px;}
.wrap{max-width:580px;margin:0 auto;background:#fff;border-radius:10px;border:1px solid #e0e0e0;overflow:hidden;}
.head{background:#1a1a1a;color:#fff;padding:22px 32px;}
.head h1{margin:0;font-size:18px;font-weight:600;}
.body{padding:28px 32px;}
.body p{font-size:14px;line-height:1.6;color:#333;margin:0 0 12px;}
table{width:100%;border-collapse:collapse;font-size:14px;margin:16px 0;}
td{padding:10px 0;border-bottom:1px solid #f0f0f0;}
td:first-child{color:#666;width:42%;}
td:last-child{font-weight:600;text-align:right;}
.badge-ok{display:inline-block;background:#e6f9ee;color:#1a7a3a;border-radius:20px;padding:4px 14px;font-size:13px;font-weight:600;margin-bottom:16px;}
.badge-pend{display:inline-block;background:#fffbeb;color:#8a5a00;border-radius:20px;padding:4px 14px;font-size:13px;font-weight:600;margin-bottom:16px;}
.box-info{background:#f0f4ff;border:1px solid #c5d3f0;border-radius:8px;padding:16px;margin:16px 0;font-size:14px;}
.box-info strong{display:block;margin-bottom:6px;color:#1a1a1a;}
.foot{padding:16px 32px;background:#fafafa;border-top:1px solid #f0f0f0;font-size:12px;color:#999;line-height:1.6;}
</style></head><body>
<div class="wrap">
<div class="head"><h1>' . h($siteName) . ' &mdash; ' . h($titulo) . '</h1></div>
<div class="body">' . $contenido . '</div>
<div class="foot">' . nl2br(h($footer)) . '</div>
</div></body></html>';
    }

    public static function tplVerificacion(array $user, string $link): string
    {
        $c = '<p>Hola <strong>' . h($user['name']) . '</strong>,</p>
<p>Gracias por registrarte. Verifica tu dirección de email haciendo clic en el botón:</p>
<p style="text-align:center;margin:24px 0;">
  <a href="' . h($link) . '" style="background:#1a1a1a;color:#fff;text-decoration:none;padding:12px 28px;border-radius:8px;font-weight:600;font-size:14px;">Verificar email</a>
</p>
<p style="font-size:12px;color:#999;">Si no te registraste en este sitio, ignora este email.</p>';
        return self::tplBase('Verifica tu email', $c);
    }

    public static function tplRecuperarPassword(array $user, string $link): string
    {
        $c = '<p>Hola <strong>' . h($user['name']) . '</strong>,</p>
<p>Recibimos una solicitud para restablecer tu contraseña:</p>
<p style="text-align:center;margin:24px 0;">
  <a href="' . h($link) . '" style="background:#1a1a1a;color:#fff;text-decoration:none;padding:12px 28px;border-radius:8px;font-weight:600;font-size:14px;">Restablecer contraseña</a>
</p>
<p style="font-size:12px;color:#999;">Este enlace caduca en 2 horas. Si no solicitaste el cambio, ignora este email.</p>';
        return self::tplBase('Recuperar contraseña', $c);
    }

    public static function tplPedidoPendiente(array $inscripcion, array $evento, array $user, array $entradas): string
    {
        $metodo = $inscripcion['metodo_pago'];
        $pedido = $inscripcion['numero_pedido'];
        $total  = number_format((float)$inscripcion['precio_total'], 2, ',', '.') . ' €';

        $instrucciones = '';
        if ($metodo === 'bizum') {
            $tel = getSetting('bizum_telefono', 'No configurado');
            $instrucciones = '<div class="box-info">
<strong>📱 Pago por Bizum</strong>
Envía <strong>' . $total . '</strong> al número <strong>' . h($tel) . '</strong><br>
En el concepto indica: <strong>' . h($pedido) . '</strong> y tu nombre completo.
</div>';
        } elseif ($metodo === 'transferencia') {
            $iban = getSetting('transferencia_iban', 'No configurado');
            $banco = getSetting('transferencia_banco', '');
            $instrucciones = '<div class="box-info">
<strong>🏦 Transferencia bancaria</strong>
Transfiere <strong>' . $total . '</strong> a:<br>
IBAN: <strong>' . h($iban) . '</strong>' . ($banco ? '<br>' . h($banco) : '') . '<br>
Concepto: <strong>' . h($pedido) . '</strong> y tu nombre completo.
</div>';
        }

        $listaAsistentes = '';
        foreach ($entradas as $i => $e) {
            $listaAsistentes .= '<tr><td>' . ($i + 1) . '. ' . h($e['nombre_asistente']) . ($e['es_titular'] ? ' (titular)' : '') . '</td><td></td></tr>';
        }

        $c = '<p>Hola <strong>' . h($user['name']) . '</strong>,</p>
<p>Hemos recibido tu solicitud de inscripción al evento:</p>
<span class="badge-pend">⏳ Pago pendiente de confirmar</span>
<table>
  <tr><td>Evento</td><td>' . h($evento['nombre']) . '</td></tr>
  <tr><td>Número de pedido</td><td><strong>' . h($pedido) . '</strong></td></tr>
  <tr><td>Personas inscritas</td><td>' . (int)$inscripcion['num_personas'] . '</td></tr>
  <tr><td>Total</td><td><strong>' . $total . '</strong></td></tr>
</table>
<table><tr><th style="text-align:left;color:#666;font-weight:normal;">Asistentes</th><th></th></tr>' . $listaAsistentes . '</table>
' . $instrucciones . '
<p style="font-size:13px;color:#666;">Una vez confirmado el pago, recibirás las entradas con códigos QR por email.</p>';

        return self::tplBase('Inscripción pendiente de pago', $c);
    }

    public static function tplEntradas(array $inscripcion, array $evento, array $user): string
    {
        $pedido = $inscripcion['numero_pedido'];
        $total  = number_format((float)$inscripcion['precio_total'], 2, ',', '.') . ' €';

        $stE = db()->prepare('SELECT COUNT(*) FROM entradas WHERE inscripcion_id=?');
        $stE->execute([$inscripcion['id']]);
        $numEntradas = (int)$stE->fetchColumn();

        $stC = db()->prepare('SELECT COUNT(*) FROM consumiciones WHERE inscripcion_id=?');
        $stC->execute([$inscripcion['id']]);
        $numConsumiciones = (int)$stC->fetchColumn();

        $soloConsumiciones = $numEntradas === 0 && $numConsumiciones > 0;

        if ($soloConsumiciones) {
            $titulo = 'Tus consumiciones - ' . $evento['nombre'];
            $intro  = 'Tu pedido ha sido <strong>confirmado</strong>. Adjunto encontrarás los tickets de tus consumiciones con códigos QR.';
            $filaPersonas = '';
        } elseif ($numConsumiciones > 0) {
            $titulo = 'Tus entradas y consumiciones - ' . $evento['nombre'];
            $intro  = 'Tu inscripción ha sido <strong>confirmada</strong>. Adjunto encontrarás tus entradas y los tickets de las consumiciones de barra, todos con códigos QR.';
            $filaPersonas = '<tr><td>Personas inscritas</td><td>' . (int)$inscripcion['num_personas'] . '</td></tr>';
        } else {
            $titulo = 'Tus entradas - ' . $evento['nombre'];
            $intro  = 'Tu inscripción ha sido <strong>confirmada</strong>. Adjunto encontrarás las entradas con códigos QR.';
            $filaPersonas = '<tr><td>Personas inscritas</td><td>' . (int)$inscripcion['num_personas'] . '</td></tr>';
        }

        $c = '<p>Hola <strong>' . h($user['name']) . '</strong>,</p>
<p>' . $intro . '</p>
<span class="badge-ok">✓ ' . ($soloConsumiciones ? 'Pedido confirmado' : 'Inscripción confirmada') . '</span>
<table>
  <tr><td>Evento</td><td>' . h($evento['nombre']) . '</td></tr>
  <tr><td>Fecha</td><td>' . ($evento['fecha_evento'] ? date('d/m/Y H:i', strtotime($evento['fecha_evento'])) : 'Por confirmar') . '</td></tr>
  <tr><td>Lugar</td><td>' . h($evento['lugar'] ?? 'Por confirmar') . '</td></tr>
  <tr><td>Número de pedido</td><td>' . h($pedido) . '</td></tr>
  ' . $filaPersonas . '
  ' . ($numConsumiciones > 0 ? '<tr><td>Consumiciones</td><td>' . $numConsumiciones . '</td></tr>' : '') . '
  ' . (!$evento['es_gratuito'] ? '<tr><td>Total pagado</td><td>' . $total . '</td></tr>' : '') . '
</table>
<p style="font-size:13px;color:#666;">Cada código QR adjunto corresponde a un único uso (una entrada o una consumición). Preséntalo en el evento.</p>
<p style="font-size:13px;color:#666;">También puedes acceder a tus pedidos en cualquier momento desde tu cuenta.</p>';

        return self::tplBase($titulo, $c);
    }

    public static function tplNuevoPedidoAdmin(array $inscripcion, array $evento, array $user): string
    {
        $pedido = $inscripcion['numero_pedido'];
        $total  = number_format((float)$inscripcion['precio_total'], 2, ',', '.') . ' €';
        $base   = rtrim(defined('APP_BASE_URL') ? APP_BASE_URL : getSetting('app_base_url'), '/');
        $link   = $base . '/admin/inscripciones/detalle.php?id=' . (int)$inscripcion['id'];

        $c = '<p>Se ha recibido un nuevo pedido en <strong>' . h($evento['nombre']) . '</strong>:</p>
<table>
  <tr><td>Número de pedido</td><td><strong>' . h($pedido) . '</strong></td></tr>
  <tr><td>Cliente</td><td>' . h($user['name']) . ' (' . h($user['email']) . ')</td></tr>
  <tr><td>Método de pago</td><td>' . h($inscripcion['metodo_pago']) . '</td></tr>
  <tr><td>Total</td><td><strong>' . $total . '</strong></td></tr>
</table>
<p style="font-size:13px;color:#666;">Este aviso se envía al recibir el pedido, independientemente de si el pago ya está confirmado.</p>
<p style="text-align:center;margin:24px 0;">
  <a href="' . h($link) . '" style="background:#1a1a1a;color:#fff;text-decoration:none;padding:12px 28px;border-radius:8px;font-weight:600;font-size:14px;">Ver pedido</a>
</p>';

        return self::tplBase('Nuevo pedido recibido', $c);
    }

    // ── NOTIFICACIONES ADMIN ─────────────────────────────────────────────────

    // Avisa al admin propietario del evento y a todos los superadmin de que se
    // ha recibido un pedido nuevo (entradas y/o consumiciones), sin esperar a
    // que el pago esté confirmado.
    public static function notificarNuevoPedidoAdmin(array $inscripcion, array $evento, array $user): void
    {
        $destinatarios = [];
        if (!empty($evento['admin_id'])) {
            $st = db()->prepare("SELECT email, name, username FROM admin_users WHERE id=? AND active=1");
            $st->execute([$evento['admin_id']]);
            if ($a = $st->fetch()) {
                $destinatarios[$a['email']] = $a['name'] ?: $a['username'];
            }
        }
        $st2 = db()->query("SELECT email, name, username FROM admin_users WHERE role='superadmin' AND active=1");
        foreach ($st2->fetchAll() as $a) {
            if (!empty($a['email'])) $destinatarios[$a['email']] = $a['name'] ?: $a['username'];
        }

        if (empty($destinatarios)) return;

        $body = self::tplNuevoPedidoAdmin($inscripcion, $evento, $user);
        $m = new self();
        foreach ($destinatarios as $email => $name) {
            if (!$email) continue;
            $m->send($email, $name, 'Nuevo pedido de ' . mb_strtolower(etiquetaPedido((int)$inscripcion['id'])) . ' — ' . $evento['nombre'], $body);
        }
    }

    public static function tplEntradaIndividual(array $entrada, array $evento, array $inscripcion): string
    {
        $c = '<p>Te enviamos tu entrada para el evento:</p>
<table>
  <tr><td>Evento</td><td><strong>' . h($evento['nombre']) . '</strong></td></tr>
  <tr><td>Asistente</td><td><strong>' . h($entrada['nombre_asistente']) . '</strong></td></tr>
  <tr><td>Fecha</td><td>' . ($evento['fecha_evento'] ? date('d/m/Y H:i', strtotime($evento['fecha_evento'])) : 'Por confirmar') . '</td></tr>
  <tr><td>Lugar</td><td>' . h($evento['lugar'] ?? '') . '</td></tr>
  <tr><td>Pedido</td><td>' . h($inscripcion['numero_pedido']) . '</td></tr>
</table>
<p style="font-size:13px;color:#666;">Adjunto encontrarás tu entrada con código QR. Preséntala en la entrada del evento.</p>';

        return self::tplBase('Tu entrada - ' . $evento['nombre'], $c);
    }
}
