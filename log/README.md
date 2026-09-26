# Vestia Log

Panel privado: https://log.armario.89.116.30.213.sslip.io

PHP y SQLite para trazas; lectura de MariaDB para cuentas y trabajos existentes.
Solo admite cuentas existentes en raul y adrian. Cada identidad es (entorno, ID de usuario)
y únicamente puede leer sus propios trabajos y llamadas. La sesión caduca a las 8 horas;
cambiar la contraseña o eliminar la cuenta invalida el acceso en la siguiente petición.
No se importa bootstrap de Vestia y no se ejecutan sus migraciones.

Capture.php se integra en OpenAI::request y recibe contexto desde scripts/worker.php.
Nunca conserva cabeceras de autorización, claves ni imágenes en base64. Conserva
metadatos y huellas de las imágenes; las fotos personales mantienen su borrado original.
No se realizan llamadas de IA para resumir ni se estiman costes sin tarifas verificadas.
Una caída del registro no debe impedir la llamada de IA.

Los trabajos históricos muestran entradas/resultados de jobs, no prompts reconstruidos.
La reutilización de un resultado conserva el trabajo original; no genera otra llamada.
El listado carga los últimos 200 trabajos de la cuenta y se actualiza cada 10 segundos.
Las trazas se conservan 30 días. La limpieza diaria se instala en /etc/cron.d/vestia-log.
Los trabajos originales siguen su política de almacenamiento anterior.

Archivos públicos: public/. Base de datos privada: storage/events.sqlite.
Los cambios en workers y OpenAI.php se respaldan antes de activar la instrumentación.
Las actualizaciones futuras de cada entorno deben conservar esta integración.

Validación: sintaxis PHP/JS, captura con respuestas simuladas (sin coste API), HTTPS,
rechazo de acceso anónimo, CSRF, contraseña incorrecta, aislamiento entre cuentas,
filtros y visualización de escritorio/móvil mediante navegador real.
