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

## Estructura

```
app/       bootstrap.php (env, sesión, PDO, migraciones) · OpenAI.php (análisis e imágenes)
database/  migrations/*.sql (esquema versionado, se aplica solo)
public/    index.php (front controller) · outfit-image.php · assets/ · uploads/
scripts/   worker.php (cola de jobs) · migrate.php · seed_*.php · import_official_catalog.php
storage/   official_catalog.json · outfits/ · logs/
```
