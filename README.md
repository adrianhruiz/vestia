# Vestia

Armario digital: digitaliza tus prendas y genera combinaciones y recomendaciones de
estilo con IA.

PHP 8 plano (sin framework ni Composer) · MySQL 8 · Apache · OpenAI · worker en segundo plano.

## Arrancar en local

```bash
git clone https://github.com/adrianhruiz/vestia.git && cd vestia
git config core.hooksPath .githooks      # rechaza el push directo a main y develop
cp .env.example .env                     # rellénalo (ver Credenciales)

mysql -u root -p -e "CREATE DATABASE vestia_local CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
php scripts/seed_demo.php                # datos de ejemplo

php -S localhost:8000 -t public          # aplicación en http://localhost:8000
php scripts/worker.php                   # en otra terminal
```

El esquema vive en `database/migrations/*.sql` y se aplica solo: al abrir la conexión, la
aplicación ejecuta las migraciones que falten y las anota en la tabla `schema_migrations`.
Así todas las bases de datos —la tuya, la de tu slot, `pre` y `prod`— acaban con el mismo
esquema, se hayan creado cuando se hayan creado. Para aplicarlas y ver el estado a mano:

```bash
php scripts/migrate.php
```

## Credenciales

En el repositorio no hay ninguna y no debe haberla nunca: `.env` está en `.gitignore` y lo
versionado es `.env.example`. Para trabajar, trae el `.env` real desde tu entorno del VPS:

```bash
scp root@89.116.30.213:/home/saymon/php-projects/armario/slots/<tu-nombre>/.env .env
```

Si añades una variable, refléjala en `.env.example`.

## Entornos del VPS

Cada entorno es un clon de este repositorio con su propia base de datos, su `.env` y su
worker. El VPS lee el repositorio con una deploy key de solo lectura.

| Entorno | URL | Rama | Para qué |
|---------|-----|------|----------|
| **adrian** | https://adrian.armario.89.116.30.213.sslip.io | la que quieras | pruebas de Adrián |
| **raul** | https://raul.armario.89.116.30.213.sslip.io | la que quieras | pruebas de Raúl |
| **pre** | https://pre.armario.89.116.30.213.sslip.io | `develop` | integración |
| **prod** | https://armario.89.116.30.213.sslip.io | `main` | producción |

Los dos primeros son **slots personales**: cada uno tiene su directorio, su base de datos y
su worker, así que podéis probar ramas distintas a la vez sin pisaros. `pre` y `prod` no se
tocan a mano, siguen a `develop` y `main`.

## Probar una feature

Despliega tu rama en **tu** slot, nunca en `pre` ni en `prod`:

```bash
ssh root@89.116.30.213
cd /home/saymon/php-projects/armario/slots/<tu-nombre>
git fetch origin && git checkout <tu-rama> && git pull
systemctl restart armario-slot@<tu-nombre>
```

El despliegue no es automático: tu slot no se entera de un `git push` por sí solo, así que
repite este checkout+pull+restart cada vez que quieras probar una rama nueva o los últimos
commits de la misma rama.

Y revisa el resultado en la URL de tu slot. Si algo falla:

```bash
tail -f /var/log/apache2/armario-slot-<tu-nombre>-error.log
journalctl -u armario-slot@<tu-nombre> -f
```

Cuando va bien, abre el pull request a `develop`.

## Desplegar pre y prod

```bash
# pre (tras mergear a develop)
cd /home/saymon/php-projects/armario/pre && git pull && php scripts/migrate.php && systemctl restart armario-pre-worker

# prod (tras mergear a main)
cd /home/saymon/php-projects/armario/prod && git pull && php scripts/migrate.php && systemctl restart armario-prod-worker
```

`scripts/migrate.php` no es obligatorio —la primera petición aplicaría las migraciones
igual—, pero así el despliegue falla en tu terminal y no en la cara del primer usuario.

El `.env` está fuera de git, así que ningún despliegue lo pisa.

## Colaborar

Todo el código entra por pull request: nadie hace `push` directo a `main` ni a `develop`.
Las ramas de trabajo salen de `develop` con prefijo `feature/`, `fix/`, `refactor/`,
`chore/` o `hotfix/`.

```bash
git checkout develop && git pull
git checkout -b feature/<nombre>
# trabajar, probar en tu slot
git push -u origin feature/<nombre>
gh pr create --base develop --fill
```

El flujo completo —revisión, publicación a producción, hotfixes y convenciones— está en
**[CONTRIBUTING.md](CONTRIBUTING.md)**.

## Versión 0.0.7 y cuentas de prueba

El archivo `VERSION` identifica la versión del código desplegado. La cabecera muestra
«Versión 0.0.7» en la portada, dentro de la aplicación, en el catálogo y en la página
pública de un look compartido, también en móvil.
Para cambiar la versión en futuros despliegues, actualiza ese archivo en la rama correspondiente.

Los slots `adrian` y `raul` disponen de estas cuentas ficticias; se inicia sesión con el correo:

| Usuario | Correo | Armario en cada slot |
|---------|--------|----------------------|
| adri | adri@vestia.com | 60 prendas del catálogo Inditex |
| raul | raul@vestia.com | 60 prendas del catálogo Inditex |

Cada cuenta tiene su propio perfil de estilo, favoritos, contador de usos y armario privado.
Las cuentas existen en ambos slots y sus datos son independientes entre bases de datos.
Las prendas proceden de `storage/official_catalog.json`: conservan nombre, marca, referencia,
color, talla, categoría, enlace oficial y precio de referencia, con su foto y miniatura WebP
en `public/assets/catalog/`. Es una instantánea del catálogo incluido en el repositorio;
los precios, tallas y disponibilidad no se actualizan en tiempo real.

Las contraseñas se generan de forma aleatoria, se entregan por un canal privado y **no se
documentan ni se guardan en Git**. La base de datos almacena únicamente sus hashes.
`scripts/seed_test_users.php` recibe por entrada estándar un JSON con las claves `adri` y
`raul` y contraseñas de al menos 16 caracteres. Valida las 120 imágenes antes de insertar,
crea ambas cuentas y sus armarios en una transacción y omite las cuentas que ya existen,
sin cambiar sus contraseñas ni borrar prendas o análisis. No uses `seed_demo.php` ni
`import_official_catalog.php` para estas cuentas: esos scripts reemplazan datos existentes.

Cada slot despliega la rama que se esté probando en él, y hoy los dos están en `develop`.
PRE y PROD conservan su flujo habitual.

## Estructura

```
app/       bootstrap.php (env, sesión, PDO, migraciones) · OpenAI.php (análisis e imágenes)
database/  migrations/*.sql (esquema versionado, se aplica solo)
public/    index.php (front controller) · outfit-image.php · assets/ · uploads/
scripts/   worker.php (cola de jobs) · migrate.php · seed_*.php · import_official_catalog.php
storage/   official_catalog.json · outfits/ · logs/
```

## Escaneo de etiquetas (0.0.7)

En «Escanear etiqueta» o «Añadir prenda», fotografía la referencia impresa o escríbela.
Revisa marca, referencia y talla, pulsa «Buscar referencia» y elige una coincidencia.
El formulario se completa con los datos del catálogo; la talla se toma de la etiqueta
solo cuando se ha podido leer. Revisa los campos y guarda. Puedes usar la fotografía
oficial disponible o subir una tuya. Si falla su descarga, puedes desmarcarla y guardar.

La búsqueda usa el catálogo privado ya recopilado y distingue modelo y color. No realiza
una búsqueda nueva en la web de la tienda ni decodifica códigos de barras. Si no hay
coincidencias, permite pegar el enlace o completar la prenda manualmente. Las sugerencias
caducan a los 30 minutos y pertenecen a la sesión que hizo la consulta. El visor de
administración mantiene sus permisos; los usuarios acceden únicamente a las coincidencias
de sus búsquedas y sus fotos. Precio y disponibilidad no se consultan en tiempo real.

Dependencias en el servidor: PHP con GD, EXIF, PDO SQLite y cURL, `timeout`, y Tesseract
con los idiomas inglés y español (`apt-get install tesseract-ocr tesseract-ocr-spa`).
La lectura es local, admite JPG/PNG/WebP hasta 8 MB y 24 megapíxeles, aplica orientación
EXIF y elimina el fichero temporal al acabar. No se conserva la foto de la etiqueta.
Cada lectura limita el tiempo de OCR y la sesión admite seis lecturas por minuto.

Pruebas: `php tests/run.php LabelScan` (SQLite, GD y Tesseract necesarios para todos los
casos). El resto de pruebas siguen ejecutándose con `php tests/run.php`; las que usan
MySQL requieren exclusivamente una base desechable mediante `TEST_DB_DATABASE`.

El servicio de imágenes debe desplegar también `catalog/image_gateway.py`: la opción
interna `exact=1` evita sustituir una fotografía fallida por la de otro color. Las
selecciones de una captura anterior dejan de servir fotos si el catálogo se actualiza.
La prueba de navegador está en `tests/browser_label_scan.py` y usa imágenes de etiqueta
sintéticas, OCR real y las cuentas de prueba existentes; elimina las prendas que crea.
