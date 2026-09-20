# Guía de colaboración — Vestia

## Puesta en marcha (una vez por clon)

```bash
git clone https://github.com/adrianhruiz/vestia.git
cd vestia
git config core.hooksPath .githooks   # bloquea el push directo a main y develop
git config commit.template .gitmessage
```

El hook `pre-push` es la red de seguridad: si intentas hacer `push` a `main` o `develop`
directamente, lo rechaza y te recuerda abrir un PR.

## Ramas permanentes

| Rama      | Propósito                                        | Se toca |
|-----------|--------------------------------------------------|---------|
| `main`    | Lo que está en producción en el VPS              | Solo vía PR desde `develop` |
| `develop` | Integración: lo siguiente que saldrá a producción | Solo vía PR desde una rama de trabajo |

Nadie hace `push` directo a `main` ni a `develop`. Todo entra por pull request.

`develop` es la rama por defecto del repositorio, así que los PR nuevos apuntan ahí solos.

## Ramas de trabajo

Se crean **siempre desde `develop`** y se nombran por tipo:

| Prefijo     | Para qué                                  | Ejemplo                        |
|-------------|-------------------------------------------|--------------------------------|
| `feature/`  | Funcionalidad nueva                       | `feature/filtro-por-temporada` |
| `fix/`      | Corrección de un fallo                    | `fix/subida-de-imagenes`       |
| `refactor/` | Reorganizar código sin cambiar el comportamiento | `refactor/cliente-openai` |
| `chore/`    | Mantenimiento, dependencias, scripts      | `chore/limpiar-seeds`          |
| `hotfix/`   | Urgencia en producción (sale de `main`)   | `hotfix/error-500-en-login`    |

## Ciclo de una funcionalidad

```bash
# 1. Partir de develop actualizada
git checkout develop
git pull

# 2. Crear la rama de trabajo
git checkout -b feature/filtro-por-temporada

# 3. Trabajar y commitear
git add -A
git commit -m "Add season filter to wardrobe listing"

# 4. Subir la rama
git push -u origin feature/filtro-por-temporada

# 5. Abrir el PR contra develop
gh pr create --base develop --fill
```

A partir de ahí: la otra persona revisa, se comentan los cambios si hace falta, y cuando
está aprobado se mergea. La rama se borra sola al mergear.

## Publicar a producción

Cuando `develop` tiene lo que queremos publicar:

```bash
gh pr create --base main --head develop --title "Release: <resumen>"
```

Se revisa el PR completo (es el diff acumulado desde la última publicación), se mergea,
y se despliega en el VPS desde `main`.

## Urgencias en producción

Un `hotfix/` sale de `main`, no de `develop`:

```bash
git checkout main && git pull
git checkout -b hotfix/error-500-en-login
# ... arreglar, commitear, push ...
gh pr create --base main --fill
```

Tras mergear en `main`, hay que traer el arreglo de vuelta a `develop` para no perderlo:

```bash
gh pr create --base develop --head main --title "Sincronizar hotfix en develop"
```

## Convenciones

- **Código y comentarios en inglés.** Los mensajes de commit también.
- Mensaje de commit: modo imperativo y en una línea (`Add season filter`, no `Añadidos filtros`).
- Los `.env` **nunca** se suben: están en `.gitignore` y contienen la API key de OpenAI
  y las contraseñas de base de datos reales. Si añades una variable nueva, refléjala en
  `.env.example` con un valor de ejemplo.
- `prod/` y `pre/` son dos entornos del mismo proyecto. Si un cambio aplica a ambos,
  aplícalo a ambos en el mismo PR.

## Revisión

Un PR se puede mergear cuando tiene una aprobación de la otra persona y no quedan
conversaciones abiertas. Quien aprueba es quien mergea, salvo que se acuerde otra cosa.
