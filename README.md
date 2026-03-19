# mediaXm

**Gestor de Archivos Multimedia**  
Sistema sencillo en PHP para organizar, subir, visualizar y gestionar música, videos e imágenes.

![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4?style=flat&logo=php)
![MySQL](https://img.shields.io/badge/MySQL-4479A1?style=flat&logo=mysql&logoColor=white)

**Estructura del proyecto**

mediaXm/
├── config/          → Configuración (DB, rutas, etc.)
├── models/          → Entidades y consultas a base de datos
├── patterns/        → Implementaciones de patrones de diseño
├── uploads/         → Archivos multimedia subidos
├── public/          → (recomendado) index.php + assets
├── schema.sql       → Estructura de tablas MySQL
└── README.md

## ¿Qué es mediaXm?

Un gestor de medios ligero y educativo que permite:

- Subir archivos multimedia (imágenes, audio, video)
- Organizarlos por categorías o etiquetas
- Visualizarlos en una interfaz web simple
- Aplicar y demostrar **patrones de diseño** clásicos en un contexto real

Ideal para aprender arquitectura limpia en PHP puro y patrones GoF (Gang of Four).

## Características principales

- Subida y almacenamiento seguro de archivos multimedia
- Base de datos MySQL para metadatos (nombre, tipo, tamaño, fecha, etc.)
- Separación de responsabilidades (models, config, lógica de negocio)
- Demostración práctica de **patrones de diseño** en la carpeta `patterns/`

## Patrones de diseño implementados

| Patrón       | Ubicación                     | ¿Dónde y por qué se usa?                                                                 |
|--------------|-------------------------------|-------------------------------------------------------------------------------------------|
| **Singleton** | `patterns/Singleton.php`     | Conexión única a la base de datos (evita múltiples conexiones abiertas)                   |
| **Factory**   | `patterns/Factory.php`       | Crear diferentes tipos de objetos Media (Image, Video, Audio) sin exponer lógica         |
| **Strategy**  | `patterns/Strategy.php`      | Diferentes formas de procesar/validar archivos según su tipo (ej: compresión de imagen vs video) |
| **Observer**  | `patterns/Observer.php`      | Notificar cuando se sube un archivo (ej: generar thumbnail, enviar email, loggear)       |
| ...           | (puedes ir agregando más)    | —                                                                                         |

## Tecnologías utilizadas

- PHP 8.1+
- MySQL / MariaDB
- HTML + CSS básico (puedes mejorar con Bootstrap/Tailwind)
- PHP puro (sin frameworks pesados → ideal para aprender)

## Instalación

1. Clona el repositorio

   ```bash
   git clone https://github.com/edwino-dev/mediaXm.git
   cd mediaXm
   
2. Crea la base de datos y ejecuta el esquema

   ```bash
   mysql -u root -p < schema.sql
   
3. Configura la conexión en `config/database.php`

```php
return [
    'host'     => 'localhost',
    'dbname'   => 'media_xm',
    'user'     => 'root',
    'password' => '',
    'charset'  => 'utf8mb4',
];
```
4. Asegúrate que la carpeta uploads/ tenga permisos de escritura
```bash
 chmod -R 775 uploads/
# O si estás en Windows (XAMPP/WAMP), hazlo manualmente desde el explorador o con
```
5. Abre en el navegador: http://localhost/mediaXm/public/ (o la ruta donde lo pusiste)

Uso básico

- Accede a index.php
- Sube archivos desde el formulario
- Visualiza la lista de medios subidos

