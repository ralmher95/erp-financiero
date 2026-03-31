ERP Financiero — Sistema de Gestión Contable y OCR
Este proyecto consiste en el desarrollo de un sistema integral de gestión financiera construido en PHP que permite automatizar la contabilidad, generar reportes profesionales y digitalizar documentos físicos mediante tecnología OCR.El objetivo del proyecto ha sido profundizar en el desarrollo backend con PHP, la gestión de bases de datos relacionales y la integración de librerías externas para resolver problemas complejos como el reconocimiento óptico de caracteres y la generación de documentos dinámicos.Además, este proyecto forma parte de mi aprendizaje como desarrollador y ha sido diseñado como una pieza clave de mi portfolio profesional, demostrando capacidades de arquitectura de software y seguridad.

¿Qué hace este proyecto? 
El sistema permite:
Gestión Contable: Controlar el Plan General Contable y los asientos de la empresa.
Digitalización OCR: Subir fotos de tickets o facturas y extraer el texto automáticamente mediante Tesseract OCR.
Generación de Reportes: Crear facturas y balances financieros en formato PDF de manera automatizada.Dashboard Financiero: Visualizar métricas clave del estado económico en una interfaz centralizada.Seguridad de Datos: Gestión protegida de conexiones a base de datos y manejo de sesiones.
Gestión de Almacenamiento: Organización eficiente de archivos físicos y digitales en el servidor.
Tecnologías y herramientas utilizadas.
Backend y Lógica. PHP (Framework MVC)
Python (Scripts de utilidad/procesamiento)
Base de DatosMySQL / MariaDB
Lenguajes de Frontend. HTML5/CSS3/JavaScript
Librerías externas. Bootstrap / Chart.js / jQuery / PDF.js / Tesseract OCR / PHPMailer

Conceptos clave del proyecto:
Durante el desarrollo de este ERP se han puesto en práctica conceptos avanzados de ingeniería de software:
Autoloading PSR-4: Implementación de una estructura de clases profesional y organizada.
Integración de Motores Externos: Configuración y comunicación del backend con Tesseract OCR a nivel de sistema.
Manipulación de Buffers: Uso de dompdf para renderizar vistas complejas en documentos descargables.
Seguridad en Entornos: Implementación de archivos de configuración protegidos y uso de .gitignore para credenciales.
Gestión de Archivos: Creación de un sistema de subida y almacenamiento seguro en el servidor (storage/).
Arquitectura Limpia: Separación de la lógica de negocio (src/) de los archivos públicos (public/).

Capturas del proyecto

## Dashboard Principal
![Dashboard](docs/img/home.png)

## Módulo de OCR en Acción
![OCR en Acción](docs/img/ocr-process.png)

## Reporte PDF Generado
![Reporte PDF](docs/img/pdf-report.png)

Estructura del proyecto
La estructura principal del repositorio es la siguiente:

erp-financiero/
├─ README.md
├─ .gitignore
├─ config/
│  └─ db_connect.php         # Configuración (Excluido de Git)
├─ docs/
│  └─ img/                   # Capturas de pantalla
├─ public/
│  └─ assets/                # CSS, JS e imágenes del front
├─ src/
│  └─ App/                   # Lógica de clases PSR-4
└─ storage/
   └─ tickets/               # Almacén de archivos procesados

Instalación del proyecto.
Para desplegar este proyecto en un entorno local, sigue estos pasos:
Clonar el repositorio: Bashgit clone https://github.com/ralmher95/erp-financiero.git

Instalar dependencias: 
Ejecutar Composer para descargar las librerías necesarias:
Bashcomposer install

Configurar el entorno:
Crea un archivo config/db_connect.php basado en el archivo de ejemplo y configura tus credenciales de MySQL.

Instalar Tesseract OCR:
Asegúrate de tener instalado el motor Tesseract en tu sistema operativo para que la funcionalidad de lectura de tickets esté activa.
Importar base de datos:Utiliza el archivo .sql incluido (si aplica) para crear la estructura de tablas necesaria.

Aprendizaje y retos del proyecto
Este proyecto ha representado un desafío técnico importante, especialmente en la integración de herramientas que no son nativas de PHP.Uno de los mayores retos fue la configuración del motor OCR, ya que requiere una gestión precisa de permisos de lectura/escritura en el servidor y una limpieza previa de las imágenes para mejorar la precisión del reconocimiento.También profundicé en:La creación de consultas SQL optimizadas para reportes contables.La gestión de namespaces en PHP para evitar conflictos de nombres.El uso de scripts de Python para automatizar la consolidación de archivos del proyecto.Este ERP me ha ayudado a entender que una aplicación robusta no solo depende de un buen código, sino de una arquitectura que facilite el mantenimiento y la escalabilidad.

Mejoras futuras
Para las próximas versiones del sistema, tengo planeado:Implementar un sistema de autenticación mediante JWT o OAuth2.
Añadir gráficas interactivas con Chart.js en el dashboard.Mejorar el motor de OCR con procesamiento de imagen previo (filtros de contraste) mediante OpenCV.


Conclusión
Este ERP Financiero demuestra mi capacidad para construir soluciones complejas que integran diversas tecnologías (PHP, Python, OCR, SQL).A través de este desarrollo, he consolidado mis conocimientos en arquitectura de software, seguridad y automatización de procesos, creando una herramienta funcional que resuelve problemas reales del mundo contable. Es un paso adelante en mi evolución como desarrollador enfocado en soluciones eficientes y profesionales. Este proyecto ha sido diseñado como una pieza clave de mi portfolio profesional, demostrando capacidades de arquitectura de software y seguridad. Este ERP esta diseñado con fines educativos, por lo que no pretende ser una herramienta de gestión de negocios, sino una herramienta de aprendizaje y desarrollo de software.