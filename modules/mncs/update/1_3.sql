-- Uniforma l'icona del modulo custom "Incassi conti" a quella degli altri moduli sotto
-- Strumenti > Tabelle (`fa fa-circle-o`). In 1_2.sql era stata impostata `fa fa-euro`.
-- Idempotente: e' un no-op se l'icona e' gia' quella corretta.
UPDATE `zz_modules` SET `icon` = 'fa fa-circle-o' WHERE `name` = 'Incassi conti';
