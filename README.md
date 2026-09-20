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

## Cómo colaborar

El repositorio es privado y trabajamos dos personas. Todo el código entra por **pull
request**: nadie hace `push` directo a `main` ni a `develop`.

### Ramas

| Rama      | Qué contiene                          | Cómo se actualiza              |
|-----------|---------------------------------------|--------------------------------|
| `main`    | Lo que está publicado en `prod`       | PR desde `develop`             |
| `develop` | Lo siguiente que se publicará         | PR desde una rama de trabajo   |

`develop` es la rama por defecto, así que los PR nuevos ya apuntan ahí.

Las ramas de trabajo salen siempre de `develop` y llevan prefijo según el tipo:
`feature/`, `fix/`, `refactor/`, `chore/` y `hotfix/` (esta última sale de `main`).

### Preparar tu copia (una sola vez)

```bash
git clone https://github.com/adrianhruiz/vestia.git
cd vestia
git config core.hooksPath .githooks    # rechaza el push directo a main y develop
git config commit.template .gitmessage
cp .env.example .env                   # y rellénalo (ver "Credenciales")
```

### Ciclo completo de una funcionalidad

```bash
# 1. Partir de develop actualizada
git checkout develop && git pull

# 2. Crear la rama de trabajo
git checkout -b feature/filtro-por-temporada

# 3. Trabajar, commitear (mensajes en inglés e imperativo) y subir
git add -A
git commit -m "Add season filter to wardrobe listing"
git push -u origin feature/filtro-por-temporada

# 4. Desplegar esa rama en el entorno pre del VPS y comprobar que va bien
#    (ver "Cómo se prueba una funcionalidad")

# 5. Abrir el pull request contra develop
gh pr create --base develop --fill
```

A partir de ahí lo revisa la otra persona: comenta, pide cambios si hace falta y aprueba.
Cuando está aprobado se mergea y la rama se borra sola.

### Revisar un pull request

Desde la web, en la pestaña **Pull requests** del repositorio, o desde la terminal:

```bash
gh pr list                  # ver los PR abiertos
gh pr checkout <numero>     # traerte la rama para probarla
gh pr diff <numero>         # ver el diff
gh pr review <numero> --approve                        # aprobar
gh pr review <numero> --request-changes -b "motivo"    # pedir cambios
gh pr merge <numero> --squash --delete-branch          # mergear
```

Un PR se puede mergear cuando tiene la aprobación de la otra persona y no quedan
conversaciones abiertas.

### Publicar a producción

Cuando `develop` acumula lo que queremos publicar:

```bash
gh pr create --base main --head develop --title "Release: <resumen>"
```

Se revisa, se mergea y se despliega en `prod` como se indica más abajo.

### Reglas

- Código, comentarios y mensajes de commit **en inglés**. La documentación, en español.
- **Nunca** subas un `.env` ni credenciales. Si añades una variable, refléjala en
  `.env.example`.
- Prueba en `pre` antes de abrir el PR.

El detalle completo del flujo, incluidos los hotfixes, está en
[CONTRIBUTING.md](CONTRIBUTING.md).
