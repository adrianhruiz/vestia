# Fuentes

`DM Sans` y `Playfair Display`, bajo [SIL Open Font License 1.1](https://openfontlicense.org/),
servidas desde nuestro propio dominio en vez de desde `fonts.googleapis.com`.

Son las dos familias variables, subconjuntos `latin` y `latin-ext`. Las declaraciones
`@font-face` están al principio de `public/assets/app.css`, cada una con su `unicode-range`,
así que el navegador solo descarga el subconjunto que necesita para el texto de la página.

## Por qué no se cargan desde Google

El `@import` que había en `app.css` encadenaba tres viajes antes de poder pintar texto:
descargar `app.css`, descubrir el `@import`, pedir el CSS a `fonts.googleapis.com` y
finalmente pedir los `.woff2` a `fonts.gstatic.com`. Lighthouse lo medía en ~800 ms de
bloqueo de render. Sirviéndolas nosotros, los `.woff2` se piden en paralelo con el CSS
(hay un `<link rel="preload">` para los dos ficheros `latin` en el `<head>`) y no se
contacta con ningún dominio de terceros.

## Cómo actualizarlas

```bash
# 1. Pedir el CSS a Google con un User-Agent moderno (así devuelve woff2)
curl -sA 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36' \
  'https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:ital,wght@0,600;1,600&display=swap'

# 2. De ahí salen las URLs de fonts.gstatic.com. Descargar solo los bloques
#    marcados /* latin */ y /* latin-ext */ con los nombres que ya hay en esta carpeta.
# 3. Copiar los unicode-range de ese mismo CSS a los @font-face de app.css si cambian.
```

Los nombres de fichero de Google llevan un hash y cambian con cada versión de la fuente,
por eso aquí se guardan con nombres estables.

## Los .woff2 no llevan `?v=`

A diferencia del resto de assets, las fuentes se referencian por su ruta pelada, tanto en
el `@font-face` de `app.css` como en el `<link rel="preload">` del `<head>`. Tiene que ser
**la misma URL en los dos sitios**: si no coinciden, el navegador descarga cada fuente dos
veces y el preload calienta una URL que nadie pide.

Como el `@font-face` vive en un CSS estático, no hay forma de meterle el token de `asset()`
sin un paso de build. La contrapartida es que, con la caché de un año de `.htaccess`,
**sustituir una fuente obliga a cambiarle el nombre al fichero** (por ejemplo añadiendo la
versión: `dm-sans-latin.v18.woff2`) y a actualizar las dos referencias.
