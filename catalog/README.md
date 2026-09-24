# Catálogos Inditex España y visor privado de Vestia

## Qué está desplegado
Recopilación de Zara, Pull&Bear, Massimo Dutti, Bershka, Stradivarius, Oysho, Zara Home y Lefties para España. Las ocho marcas ya han devuelto fichas reales durante la ejecución iniciada el 24/09/2026. La primera recopilación completa está en curso; consultar los recuentos y estados actuales en lugar de asumir cobertura total.

Visor privado:
- https://raul.armario.89.116.30.213.sslip.io/catalog.php
- https://adrian.armario.89.116.30.213.sslip.io/catalog.php

Disponible desde el menú de las cuentas existentes raul@vestia.com y adri@vestia.com. La migración 008 asigna el permiso de catálogo a sus identificadores en cada base de datos, una sola vez. No se concede por nombre de usuario ni se acepta un rol enviado por el navegador. La página, API, fichas y fotos requieren una sesión y un permiso vigente. Otros usuarios, incluida la cuenta raul@test.com, no tienen acceso. El código se encuentra en la rama local feature/private-inditex-catalog en ambos slots. No se han publicado cambios en GitHub ni desplegado en pre/prod.

## Programación y reanudación
- inditex-catalog.timer: día 1, 04:00 Europe/Madrid, hasta 10 minutos de demora; Persistent=true recupera una ejecución perdida.
- inditex-catalog.service: harvest.py harvest, las ocho marcas con un cliente por marca; usa el catálogo vigente indicado por cada tienda. Reanuda tareas pendientes del mismo mes y conserva las capturas existentes.
- En caso de fallo, systemd reintenta a los 30 minutos, hasta tres arranques por día. Se guardan motivos y tareas sin resolver.
- inditex-catalog-media.timer: comprueba la cola de imágenes cada cinco minutos. La descarga puede seguir cuando ya han terminado las fichas.
- inditex-viewer-index.timer: actualiza el índice privado y miniaturas del visor cada cinco minutos tras finalizar su ejecución anterior. La publicación es atómica; la web consulta la versión anterior mientras se prepara la siguiente.

## Fuentes y límites
Zara usa los listados JSON públicos por categoría. Las otras siete marcas usan el árbol de categorías y productosArray de la versión 3 del servicio que usa su web. Se recopilan los datos visibles del catálogo, sin cuentas de tienda, pagos, resolución de CAPTCHA ni servicios externos contratados. El cliente HTTP mantiene compatibilidad de protocolo con un navegador Chromium.

HTTP 401/403/429 y páginas de verificación detienen la marca afectada y dejan constancia del bloqueo. El extractor no confunde una verificación servida con HTTP 200 con una ficha de producto. Un producto que la API devuelve como no encontrado se registra como unavailable, sin inventar datos.

El estado complete de una marca significa que se han procesado sus productos descubiertos en categorías. coverage_verified sigue en 0 hasta contrastar la cobertura con el catálogo total de la tienda; no equivale a una garantía de que todos los productos de la web estén incluidos. Las categorías que no ofrecen una cuadrícula de productos quedan como skipped. Las tareas o respuestas inválidas impiden declarar la extracción completa.

Se conservan datos originales, referencias, descripción, categorías, etiquetas, precios en EUR, colores, tallas, variantes y composición/cuidados disponibles. Zara aún puede carecer de detalle de tallas en sus listados; se recoge la información adicional de composición disponible. Las ausencias no se interpretan como valores negativos. No se borran productos al faltar en una ejecución.

Galerías: se conservan enlaces originales y variantes en el JSON y se solicitan imágenes de hasta 1600 px de ancho, sin recorte. Se validan con Pillow, se deduplican por URL/hash y se generan miniaturas para el visor. Se prioriza la foto de prenda aislada cuando la tienda la identifica con f1; la selección automática no tiene revisión visual exhaustiva. Ficheros no raster o inválidos se excluyen del visor. Las fotos se sirven solo tras autenticar al administrador y con Cache-Control private,no-store.

Reserva mínima de 15 GiB libres. Si se alcanza, se detiene la recopilación/descarga afectada; las tareas se conservan para reanudarlas. No se elimina histórico para liberar espacio. Las imágenes con error quedan registradas para revisión; no hay alertas por correo configuradas ni copia de seguridad externa.

## Datos y código
- /opt/inditex-catalog/harvest.py: nuevo recolector y cola de medios.
- /opt/inditex-catalog/catalog.py: esquema original y consulta de estado.
- /opt/inditex-catalog/build_viewer.py: índice privado y miniaturas validadas.
- /var/lib/inditex-catalog/catalog.sqlite3: histórico principal (SQLite WAL).
- /var/lib/inditex-catalog/media: fotografías.
- /var/lib/inditex-catalog/viewer/catalog.sqlite3: índice de lectura de la web.
- /var/lib/inditex-catalog/viewer/thumbs: miniaturas privadas.
- /home/saymon/php-projects/armario/slots/{raul,adrian}/app/Catalog.php: autorización y API.
- /home/saymon/php-projects/armario/slots/{raul,adrian}/public/catalog.php: visor.
- /opt/inditex-catalog/integration: archivos iniciales y copias anteriores a la integración.

## Comandos en la VPS
```sh
inditex-catalog status
inditex-catalog run
inditex-catalog media
inditex-catalog refresh-viewer
systemctl list-timers 'inditex*'
journalctl -u inditex-catalog.service -n 30 --no-pager
journalctl -u inditex-catalog-media.service -n 30 --no-pager
journalctl -u inditex-viewer-index.service -n 30 --no-pager
```

## Validación
Se han comprobado mediante HTTP las dos cuentas autorizadas en ambos slots (200), un usuario no autorizado (403) y ausencia de sesión (401), para la página, API e imágenes. Se ha probado el visor con Chromium: filtros, ficha, galería, menú, escritorio y móvil de 390 px sin desbordamiento horizontal ni errores de JavaScript. Los precios se normalizan desde céntimos a euros; por ejemplo 4999 se muestra como 49,99 EUR. Las capturas de diagnóstico están fuera de los directorios públicos.

## Revisión y reanudación del 24/09/2026, 21:23 UTC

Se encontraron los tres servicios detenidos desde las 19:07 UTC, con errores anteriores `database is locked` y una ejecución incompleta. Zara tenía 241 productos históricos y 1.362 categorías aún pendientes; no estaba terminada. La base principal contenía 6.823 productos de las ocho marcas y el visor conservaba una publicación anterior de 5.010.

Se verificó la versión de `harvest.py` ya instalada, que serializa las transacciones de los ocho recolectores mediante `StoreConnection` y libera el bloqueo al confirmar o revertir una transacción. Se reanudó la ejecución 2 sin borrar datos ni iniciar un histórico duplicado, y se reactivaron los temporizadores de imágenes y del visor. Tras cuatro minutos la base había crecido a 8.937 productos sin tareas fallidas nuevas. El visor publicó correctamente 6.823 productos a las 21:23 UTC; las capturas posteriores se incorporarán en sus siguientes publicaciones. Las fichas y las imágenes siguen procesándose en segundo plano; estos recuentos no representan cobertura completa.

Pruebas reproducibles, ejecutadas desde `/opt/inditex-catalog`:

```sh
.venv/bin/python -m unittest discover -s tests -v
runuser -u www-data -- php tests/check_viewer.php raul
runuser -u www-data -- php tests/check_viewer.php adrian
```

Los nueve tests Python incluyen escrituras simultáneas de las ocho marcas y otro escritor independiente, liberación del bloqueo ante un error, reanudación sin duplicados, conservación del histórico mensual y normalización de precios, tallas y disponibilidad. La comprobación HTTP usa sesiones temporales de las cuentas existentes, las destruye al terminar y no cambia contraseñas ni permisos. Verifica página, filtros de las ocho marcas, fichas, imágenes disponibles y denegación de acceso anónimo o sin permiso.

## Corrección de imágenes del visor, 24/09/2026 21:34 UTC

La primera página ordenada por fecha mostraba 24 productos sin fotografía local: sus imágenes aún estaban en la cola masiva. El visor ahora solicita las fotos de los productos visibles al servicio privado `inditex-catalog-images.service`, sin esperar al siguiente índice ni a terminar la descarga general. Las galerías también utilizan esta vía. Las imágenes locales existentes siguen disponibles por su ruta anterior.

- Código: `image_gateway.py`, más `app/Catalog.php` y `public/assets/catalog.js` de ambos slots.
- Conexión interna: socket Unix `/var/lib/inditex-catalog/viewer/image-gateway.sock`, sin puerto público.
- La ruta `catalog.php?action=product-image&id=...` exige la misma sesión y permiso de administrador que el resto del catálogo.
- El servidor acepta únicamente identificadores de productos y posiciones. Resuelve las URL guardadas, admite solo los CDN HTTPS configurados, no sigue redirecciones y valida las imágenes con Pillow.
- Reutiliza fotografías archivadas y guarda las solicitadas en `/var/lib/inditex-catalog/viewer/ondemand`, con miniaturas sin recorte. Esta caché permite verlas inmediatamente; la descarga del histórico continúa por separado.
- Recupera enlaces originales de capturas antiguas de Zara Home. Evita usar muestras de color de 40 píxeles como portada y busca una fotografía válida del mismo producto.
- Servicio habilitado al reiniciar la VPS. Copias anteriores de PHP/JS: `/opt/inditex-catalog/backups/20260924-image-fix`.

Validación: cinco pruebas específicas del servicio de imágenes, comprobaciones HTTP con ambas cuentas en los dos slots, carga de una foto de cada marca y denegación 401/403 en la nueva ruta. Chromium cargó las 24 imágenes de la primera página y la fotografía grande de una ficha en cada subdominio, sin errores JavaScript ni desbordamiento móvil. Script reproducible: `PLAYWRIGHT_BROWSERS_PATH=/opt/inditex-catalog/browsers .venv/bin/python tests/check_browser_images.py`. Capturas privadas en `diagnostics/image-fix`.

## Actualización manual desde el catálogo privado

Debajo del botón de refrescar el visor aparece `Buscar novedades del catálogo` y la fecha del último scraping. El botón inicia una nueva ejecución de las ocho marcas y crea un snapshot nuevo aunque ya exista una ejecución del mismo mes. Mientras el servicio trabaja muestra `Recopilación en curso…` y no permite otra petición. La fecha usa `finished_at` cuando existe y `started_at` durante una ejecución activa, en hora peninsular española.

La acción es `POST /catalog.php?action=scrape-start`, protegida por la sesión de administrador y CSRF. La página consulta `GET /catalog.php?action=scrape-status` cada 15 segundos. Ambos subdominios usan el mismo control raíz, con un bloqueo en `/run/vestia-catalog-control.lock`; las peticiones concurrentes solo pueden aceptar una ejecución. `vestia-catalog-control` escribe un marcador de ejecución manual que hace que `harvest.py` cree un nuevo run y lo elimina al comenzar. El timer mensual permanece sin cambios.
