-- Añade un código corto alfanumérico (6 caracteres) a cada entrada,
-- para poder introducirlo manualmente en el lector QR sin escribir
-- el token largo de 48 caracteres.
ALTER TABLE entradas
    ADD COLUMN codigo_corto VARCHAR(8) NULL AFTER qr_hash,
    ADD UNIQUE KEY uq_entradas_codigo_corto (codigo_corto);

-- Las entradas ya existentes quedarán con codigo_corto = NULL.
-- Seguirán funcionando con su QR / token largo igual que antes;
-- solo las entradas nuevas (a partir de este cambio) tendrán código corto.
