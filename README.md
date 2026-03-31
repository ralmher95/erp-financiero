# 🏦 ERP Financiero — Gestión Contable & OCR

[![PHP](https://img.shields.io/badge/PHP-7.4%2B-8892bf.svg?style=for-the-badge&logo=php)](https://www.php.net/)
[![MySQL](https://img.shields.io/badge/MySQL-005C84?style=for-the-badge&logo=mysql&logoColor=white)](https://www.mysql.com/)
[![Tesseract](https://img.shields.io/badge/OCR-Tesseract-green.svg?style=for-the-badge)](https://github.com/tesseract-ocr/tesseract)
[![License](https://img.shields.io/badge/License-MIT-yellow.svg?style=for-the-badge)](LICENSE)

Este es un sistema integral de **gestión financiera** desarrollado en PHP. Su propósito es automatizar la contabilidad empresarial, permitiendo la digitalización de documentos físicos mediante tecnología OCR y la generación de reportes profesionales en PDF.

> [!NOTE]
> Este proyecto es una pieza clave de mi portfolio profesional, diseñado para demostrar capacidades en arquitectura de software, seguridad y manejo de librerías de alto nivel.

---

## 🎯 ¿Qué hace este proyecto?

El sistema está diseñado para cubrir las necesidades core de un departamento financiero:

* **📈 Gestión Contable:** Control total del Plan General Contable y registro de asientos.
* **👁️ Digitalización OCR:** Extracción automática de texto desde tickets/facturas usando **Tesseract OCR**.
* **📄 Reportes Dinámicos:** Generación de balances y facturas en PDF listas para su uso legal.
* **📊 Dashboard Inteligente:** Visualización en tiempo real de métricas críticas del estado económico.
* **🛡️ Seguridad Senior:** Gestión protegida de sesiones y conexiones a base de datos.
* **📂 Almacenamiento Estructurado:** Organización eficiente de archivos en el servidor (`storage/`).

---

## 🛠️ Tecnologías y Herramientas

### **Backend & Lógica**
* **PHP:** Arquitectura orientada a objetos (Framework MVC propio).
* **Python:** Scripts especializados para procesamiento y consolidación de datos.
* **MySQL / MariaDB:** Base de datos relacional optimizada para integridad contable.

### **Frontend & UI**
* **Lenguajes:** HTML5, CSS3 (Diseño responsivo), JavaScript (ES6+).
* **Librerías:** Bootstrap, Chart.js (Gráficas), jQuery, PDF.js.

### **Librerías Externas (Core)**
* **Dompdf:** Renderizado de HTML a PDF.
* **Tesseract OCR:** Reconocimiento óptico de caracteres.
* **PHPMailer:** Gestión de notificaciones por correo.

---

## 🧠 Conceptos Clave Aplicados

Durante el desarrollo se implementaron estándares de ingeniería de software de alto nivel:

1.  **Autoloading PSR-4:** Estructura de clases profesional y organizada.
2.  **Integración de Motores Externos:** Comunicación fluida entre el backend y binarios del sistema (Tesseract).
3.  **Manipulación de Buffers:** Uso de `dompdf` para manejar vistas complejas en memoria.
4.  **Arquitectura Limpia:** Separación estricta entre la lógica de negocio (`src/`) y los activos públicos (`public/`).
5.  **Seguridad en Entornos:** Implementación de archivos de configuración protegidos y exclusión de secretos vía `.gitignore`.

---

## 📸 Capturas del Proyecto

| Dashboard Principal | Módulo de OCR | Reporte PDF |
| :---: | :---: | :---: |
| ![Dashboard](docs/img/home.png) | ![OCR](docs/img/ocr-process.png) | ![Reporte](docs/img/pdf-report.png) |

---

## 📂 Estructura del Repositorio

```bash
erp-financiero/
├─ config/           # Configuración de DB (Protegido)
├─ docs/             # Documentación e imágenes del proyecto
│  └─ img/           # Recursos visuales del README
├─ public/           # Punto de entrada (Assets JS/CSS)
├─ src/              # Lógica de negocio (App PSR-4)
├─ storage/          # Almacén de tickets procesados
├─ .gitignore        # Exclusión de archivos sensibles
└─ README.md         # Documentación principal

##⚙️ Instalación y Despliegue
Sigue estos pasos para ejecutar el proyecto en tu entorno local:

Clonar el repositorio:

Bash
git clone [https://github.com/ralmher95/erp-financiero.git](https://github.com/ralmher95/erp-financiero.git)
cd erp-financiero
Instalar dependencias de PHP:

Bash
composer install
Configurar el entorno:
Crea el archivo config/db_connect.php con tus credenciales de MySQL basándote en el archivo de ejemplo.

Requisito de Sistema (OCR):
Debes tener instalado Tesseract OCR en tu SO:

Ubuntu: sudo apt install tesseract-ocr

Windows: Descargar el binario oficial y añadirlo al PATH.

🚀 Aprendizaje y Retos
Este proyecto representó un desafío técnico importante, especialmente en la interoperabilidad de lenguajes.

El Reto OCR: Configurar los permisos de lectura/escritura y limpiar las imágenes para mejorar la precisión del reconocimiento fue una curva de aprendizaje valiosa.

Optimización SQL: Creación de consultas complejas para reportes contables que requieren integridad absoluta de datos.

Namespaces: Gestión avanzada de espacios de nombres en PHP para evitar colisiones en proyectos de gran escala.

🔮 Mejoras Futuras
[ ] Implementar autenticación robusta mediante JWT o OAuth2.

[ ] Integrar OpenCV para pre-procesar imágenes (filtros de contraste) antes del OCR.

[ ] Añadir gráficas interactivas avanzadas con Chart.js.

[ ] Crear una API REST para compatibilidad con aplicaciones móviles.

Conclusión
Este ERP Financiero demuestra mi capacidad para construir soluciones complejas que integran diversas tecnologías (PHP, Python, OCR, SQL).

A través de este desarrollo, he consolidado mis conocimientos en arquitectura de software, seguridad y automatización de procesos, creando una herramienta funcional que resuelve problemas reales del mundo contable. Es un paso adelante en mi evolución como desarrollador enfocado en soluciones eficientes y profesionales. Este proyecto fue diseñado con fines educativos.

Desarrollado con enfoque en la eficiencia y la arquitectura limpia por ralmher95.

