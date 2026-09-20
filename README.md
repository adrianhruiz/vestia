# Vestia

Aplicación web de armario digital: digitaliza tus prendas, las organiza por categoría y
temporada, y genera combinaciones y recomendaciones de estilo con IA.

## Stack

- **PHP 8** plano, sin framework ni Composer
- **MySQL 8** (el esquema se crea solo al arrancar, ver `app/bootstrap.php`)
- **Apache** como servidor web, apuntando a `public/`
- **OpenAI** para el análisis de estilo y la generación de imágenes de looks
- Un **worker** en segundo plano que procesa la cola de trabajos

## Estructura

```
app/
  bootstrap.php   Carga del .env, sesión, conexión PDO y migración del esquema
  OpenAI.php      Cliente de OpenAI: análisis de armario e imágenes de looks
public/
  index.php       Front controller: toda la aplicación web
  outfit-image.php  Sirve las imágenes de looks generadas
  assets/         Imágenes estáticas (hero, prendas, catálogo)
  uploads/        Fotos que sube el usuario (no versionado)
scripts/
  worker.php                  Procesa la cola de la tabla `jobs`
  seed_demo.php               Usuario de demo con datos de ejemplo
  seed_womens_wardrobe.php    Armario de ejemplo
  import_official_catalog.php Importa `storage/official_catalog.json`
  backfill_demo_images.php    Rellena imágenes que falten
storage/
  official_catalog.json  Catálogo de prendas reales
  outfits/               Looks generados (no versionado)
  logs/                  Logs de la aplicación (no versionado)
```

Tablas de base de datos: `users`, `garments`, `jobs`.

## Credenciales

**En este repositorio no hay ninguna credencial real y no debe haberla nunca.**

`.env` está en `.gitignore`. Lo que sí está versionado es `.env.example`, que tiene la
lista de variables con valores de ejemplo.

Para trabajar necesitas un `.env` real. La copia buena vive en el VPS, así que se trae
desde ahí:

```bash
# Copia el .env del entorno de pruebas a tu máquina
scp root@89.116.30.213:/home/saymon/php-projects/armario/pre/.env .env
```

Si no tienes acceso SSH al VPS, pídeselo a la otra persona del equipo por un canal
privado — nunca por el repositorio, ni por un issue, ni por un PR.

Si añades una variable de entorno nueva, añádela también a `.env.example` con un valor
de ejemplo, para que la otra persona sepa que existe.

## Puesta en marcha en local

```bash
git clone https://github.com/adrianhruiz/vestia.git
cd vestia

# Hooks y plantilla de commit (ver CONTRIBUTING.md)
git config core.hooksPath .githooks
git config commit.template .gitmessage

# Configuración
cp .env.example .env
# edita .env con tus valores, o cópialo del VPS como se indica arriba

# Base de datos local (el esquema se crea solo al primer arranque)
mysql -u root -p -e "CREATE DATABASE vestia_local CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# Datos de ejemplo
php scripts/seed_demo.php
php scripts/seed_womens_wardrobe.php

# Servidor de desarrollo
php -S localhost:8000 -t public

# En otra terminal, el worker
php scripts/worker.php
```

La aplicación queda en http://localhost:8000.

## Entornos en el VPS

El resultado final se ve siempre en el VPS, que tiene dos entornos independientes con su
propia base de datos, su propio `.env` y su propio worker:

| Entorno | URL | Ruta en el VPS | Base de datos | Worker (systemd) |
|---------|-----|----------------|---------------|------------------|
| **pre** — pruebas | https://pre.armario.89.116.30.213.sslip.io | `/home/saymon/php-projects/armario/pre` | `vestia_pre` | `armario-pre-worker` |
| **prod** — producción | https://armario.89.116.30.213.sslip.io | `/home/saymon/php-projects/armario/prod` | `vestia_prod` | `armario-prod-worker` |

`pre` es donde se prueba. `prod` solo refleja lo que hay en `main`.

## Cómo se prueba una funcionalidad

Las funcionalidades **nunca** se prueban contra el código de `main`. Se prueban desplegando
la rama de trabajo en el entorno `pre` del VPS:

```bash
# 1. En el VPS, traer la rama de la funcionalidad al entorno de pruebas
ssh root@89.116.30.213
cd /home/saymon/php-projects/armario/pre
git fetch origin
git checkout feature/<nombre>
git pull origin feature/<nombre>

# 2. Reiniciar el worker para que cargue el código nuevo
systemctl restart armario-pre-worker

# 3. Revisar el resultado en https://pre.armario.89.116.30.213.sslip.io
```

Si algo falla, los logs están en `/var/log/apache2/armario-pre-error.log` y en
`journalctl -u armario-pre-worker -f`.

Cuando la funcionalidad va bien en `pre`, se abre el pull request a `develop`. Y cuando
`develop` acumula lo que queremos publicar, se abre el pull request de `develop` a `main`
y se despliega en `prod`:

```bash
ssh root@89.116.30.213
cd /home/saymon/php-projects/armario/prod
git fetch origin && git checkout main && git pull origin main
systemctl restart armario-prod-worker
```

El `.env` de cada entorno no se toca en ningún despliegue: está fuera de git, así que
`git checkout` y `git pull` no lo pisan.

## Flujo de trabajo

El modelo de ramas, los nombres, el ciclo de un pull request y las urgencias en producción
están en [CONTRIBUTING.md](CONTRIBUTING.md).
