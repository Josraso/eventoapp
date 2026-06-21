<?php
/**
 * Librería Redsys SHA-256
 * Basada en la API oficial de Redsys (redsysHMAC256_API_PHP)
 * Compatible PHP 7.2+
 */
class RedsysAPI
{
    private $vars = [];

    public function setParameter($key, $value)
    {
        $this->vars[(string)$key] = (string)$value;
    }

    public function getParameter($key)
    {
        return $this->vars[$key] ?? '';
    }

    public function createMerchantParameters()
    {
        return base64_encode(json_encode($this->vars));
    }

    public function getDecodedMerchantParameters($data)
    {
        $decoded = json_decode(base64_decode(strtr($data, '-_', '+/')), true);
        return is_array($decoded) ? array_change_key_case($decoded, CASE_UPPER) : [];
    }

    public function generateMerchantSignature($key, $merchantParameters, $order)
    {
        $key = base64_decode($key);
        $key = $this->encrypt3DES($order, $key);
        return base64_encode(hash_hmac('sha256', $merchantParameters, $key, true));
    }

    public function validateResponse($key, $merchantParameters, $signatureReceived, $order)
    {
        $calculated = $this->generateMerchantSignature($key, $merchantParameters, $order);
        $received   = str_replace(['-', '_'], ['+', '/'], $signatureReceived);
        return hash_equals(strtoupper($calculated), strtoupper($received));
    }

    private function encrypt3DES($data, $key)
    {
        $iv = "\0\0\0\0\0\0\0\0";
        if (strlen($data) % 8) {
            $data = str_pad($data, strlen($data) + 8 - strlen($data) % 8, "\0");
        }
        return openssl_encrypt($data, 'DES-EDE3-CBC', $key, OPENSSL_RAW_DATA | OPENSSL_NO_PADDING, $iv);
    }

    public static function isResponseOk($responseCode)
    {
        $str  = ltrim((string)$responseCode, '0');
        $code = ($str === '') ? 0 : (int)$str;
        return ($code >= 0 && $code <= 99);
    }

    public static function getResponseText($responseCode)
    {
        $str  = ltrim((string)$responseCode, '0');
        $code = ($str === '') ? 0 : (int)$str;
        if ($code >= 0 && $code <= 99) return 'Pago autorizado';
        $map = [
            101 => 'Tarjeta caducada',
            102 => 'Tarjeta bajo sospecha de fraude',
            104 => 'Operación no permitida',
            116 => 'Saldo insuficiente',
            118 => 'Tarjeta no registrada',
            129 => 'CVV incorrecto',
            180 => 'Tarjeta ajena al servicio',
            184 => 'Error autenticación titular',
            190 => 'Denegado sin motivo',
            191 => 'Fecha caducidad errónea',
            904 => 'Comercio no registrado en FUC',
            909 => 'Error de sistema',
            913 => 'Pedido repetido',
            9915 => 'Cancelado por el usuario',
        ];
        return $map[$code] ?? 'Error (cod. ' . $responseCode . ')';
    }

    public static function generateOrderRef()
    {
        $ts   = str_pad((string)(time() % 999999), 6, '0', STR_PAD_LEFT);
        $rand = strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
        return $ts . $rand;
    }
}
