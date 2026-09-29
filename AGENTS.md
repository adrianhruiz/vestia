# Instrucciones para trabajar en Vestia

Estas instrucciones se aplican a todas las PR de este proyecto. Recogen el proceso
utilizado en la feature de escaneo de etiquetas (PR #48). Las instrucciones expresas
del usuario para una tarea concreta tienen prioridad.

## Flujo de trabajo de las PR

1. **Revisar el estado antes de cambiar nada.** Leer `README.md` y
   `CONTRIBUTING.md`; comprobar la rama, los cambios locales y, si se va a desplegar,
   el estado de los slots. Conservar los cambios ajenos y no sobrescribirlos.
2. **Crear una rama desde `develop` actualizada.** Usar `feature/`, `fix/`,
   `refactor/` o `chore/` según corresponda. Continuar en la misma rama para ajustes
   de una PR abierta. Los hotfixes siguen la excepción de `CONTRIBUTING.md`.
   Nunca hacer push directo a `develop` ni a `main`.
3. **Implementar el cambio completo.** Incluir interfaz, servidor, permisos,
   dependencias y documentación cuando sean necesarios. Corregir los fallos que
   impidan completar el flujo solicitado sin ampliar innecesariamente el alcance.
   Mantener código, comentarios de código y mensajes de commit en inglés.
4. **Gestionar la versión.** Si la entrega cambia de versión, actualizar `VERSION`
   y las referencias correspondientes en la documentación. Mantener el formato
   `0.0.N`: por ejemplo, la versión solicitada como «007» se expresa `0.0.7`.
   No subir la versión por cada commit, corrección de una misma entrega o cambio
   exclusivamente documental.
5. **Probar antes de desplegar.** Ejecutar las comprobaciones de sintaxis y las
   pruebas pertinentes. Añadir pruebas de comportamiento para lógica nueva,
   regresiones, permisos y casos de error cuando aporten cobertura útil. Para
   cambios coordinados de aplicación, ejecutar la suite completa disponible.
   Las pruebas MySQL deben usar una base desechable expresamente separada de las
   bases de los slots; nunca apuntar `TEST_DB_DATABASE` a datos de usuarios.
   Si faltan dependencias locales, validar en un directorio temporal de la VPS.
   Distinguir pruebas correctas, fallidas y omitidas; resolver los fallos relevantes.
6. **Publicar la rama.** Crear commits con mensajes descriptivos y hacer push de
   la rama de trabajo. No incluir `.env`, credenciales, fotos privadas, bases de
   datos, sesiones ni artefactos temporales de las pruebas.
7. **Desplegar y verificar en Raúl y Adrián.** Para cambios ejecutables, el flujo
   habitual de este proyecto incluye ambos slots, salvo que el usuario indique
   otro alcance. Comprobar que se pueden actualizar sin perder trabajo, guardar
   la revisión anterior y copias de los archivos externos al repositorio que se
   vayan a modificar, y desplegar la misma revisión mediante Git. Usar avances
   fast-forward cuando corresponda. Aplicar las migraciones necesarias y reiniciar
   únicamente los servicios afectados. No modificar PRE ni PROD como parte de
   este flujo de pruebas.
8. **Validar el resultado desplegado.** Comprobar el recorrido completo del usuario
   en ambos slots. En cambios de interfaz, usar navegador en escritorio y móvil,
   revisar capturas, desbordamientos y errores JavaScript. Cuando corresponda,
   verificar autenticación, CSRF, aislamiento entre usuarios, persistencia de
   datos y tratamiento de errores. Usar los servicios reales necesarios para
   confirmar la integración; explicar cuándo las entradas sean sintéticas o haya
   partes simuladas. Corregir lo detectado y volver a comprobar lo afectado.
9. **Limpiar y comprobar el cierre.** Eliminar únicamente los datos, sesiones,
   archivos y bases desechables creados por las propias pruebas. Confirmar rama,
   commit, versión y servicios de ambos slots. No repetir suites ya correctas si
   no hay cambios o hallazgos que lo justifiquen.
10. **Abrir la PR después de validar.** Crear la PR contra `develop` con un título
    que describa el resultado y una descripción que incluya problema resuelto,
    comportamiento final, límites, pruebas realizadas y estado del despliegue.
    Indicar dependencias o cambios en servicios externos al repositorio. Para
    cuerpos multilínea con `gh`, usar `--body-file`. Si la PR ya existe, actualizarla
    en lugar de duplicarla. No fusionarla sin la revisión prevista en
    `CONTRIBUTING.md` o autorización expresa del usuario.
11. **Informar al usuario.** Entregar el resultado, versión, enlaces a los slots y
    a la PR, pruebas relevantes y cualquier limitación pendiente. No declarar
    terminado un despliegue ni una comprobación que no se haya realizado.

## Alcance y autonomía

- Completar este proceso sin pedir confirmación reiteradamente para acciones ya
  autorizadas. Respetar las aprobaciones que exija el entorno de ejecución.
- Ajustar la verificación al cambio: una PR exclusivamente documental requiere
  revisión del contenido y del diff, no un despliegue de aplicación, reinicios de
  servicios ni pruebas de navegador.
- Los servicios compartidos del catálogo pueden afectar a ambos slots: modificar
  solo lo necesario, conservar una copia previa y verificar su compatibilidad.
- No borrar datos existentes, alterar contraseñas o sustituir trabajo ajeno para
  facilitar una prueba o un despliegue.
